<?php

namespace SLBBTools;

class Translator {
	
	const STR_EOL = "\r\n";
	
	private $includedModules;
	private $definedMethods;
	private $justIncludedModulesOutput;
	private $ast;
	private $exportPrefix;
	private $privatePrefix;
	private $symbolsToExport; // ...['values']['parts'] only (not the whole node)
	private $symbolsToImport; // alias => full name without last part
	private $symbolsToImportMethodLocation;
	private $outputProgramParts;
	private $operators;
	private $statementOperatorHandlers;
	private $curBlockLevel;
	
	public function __construct($ast, &$includedModules = null, &$definedMethods = null) {
		$this->includedModules = &$includedModules;
		if ($this->includedModules === null) {
			$this->includedModules = [];
		}
		$this->definedMethods = &$definedMethods;
		if ($this->definedMethods === null) {
			$this->definedMethods = [];
		}
		$this->justIncludedModulesOutput = [];
		$this->ast = $ast;
		$this->exportPrefix = '';
		$this->privatePrefix = '';
		$this->symbolsToExport = [];
		$this->symbolsToImport = [];
		$this->symbolsToImportMethodLocation = [];
		$this->outputProgramParts = [];
		$this->curBlockLevel = -1;
		$this->operators = [
			'+' => '+', 
			'-' => '-', 
			'*' => '*', 
			'/' => '/',
			'\'mod' => 'Mod',
			'\'pwr' => '^',
			'~' => '~',
			'>' => '>',
			'<' => '<',
			'>=' => '>=',
			'<=' => '<=',
			'=' => '=',
			'!=' => '<>',
			'<>' => '<>',
			'!' => 'Not',
			'&&' => 'And',
			'||' => 'Or',
			'^^' => 'Xor',
			'&' => 'And',
			'|' => 'Or',
			'^' => 'Xor',
			'<<' => 'Shl',
			'>>' => 'Shr',
			'\'sar' => 'Sar',
			'\'int' => 'Int',
			'\'float' => 'Float',
			'\'str' => 'Str',
			'\'tnew' => 'New',
			'\'tfirst' => 'First',
			'\'tlast' => 'Last',
			'\'tbefore' => 'Before',
			'\'tafter' => 'After',
			'\'tptr' => 'Handle'
			// ! добавить всякие +1, -1, ? (тернарный иф)
		];
		
		$this->statementOperatorHandlers = [
			'\'bbinclude' => function($blockItem) {
				$this->commitOutputLine('Include ' . $this->convertEvaluableBlock($blockItem['items'][1]));
			},
			'\'module' => function($blockItem) {
				$nodeSymbolModuleName = $blockItem['items'][1];
				$nodeSymbolModuleExports = $blockItem['items'][2];
				$this->exportPrefix = $this->nodeSymbolToOutIdentifier($nodeSymbolModuleName, null) . '__';
				$this->privatePrefix = 'local__'. \substr(\md5($this->exportPrefix), 25) . '__';
				foreach ($nodeSymbolModuleExports['items'] as $nodeSymbolExported) {
					$symbolToExportString = $nodeSymbolExported['value']['parts'][0];
					$this->symbolsToExport[] = $symbolToExportString;
					$this->symbolsToImport[\ucfirst($symbolToExportString)] = ['prefix' => $this->exportPrefix, 'symbol' => $symbolToExportString];
				}
				$this->commitOutputLine('; module: ' . \implode('.', $nodeSymbolModuleName['value']['parts']) . self::STR_EOL);
			},
			'\'use' => function($blockItem) {
				$handleItem = function(&$blockItem) {
					$part1 = $blockItem['items'][1];
					$moduleName = \implode('.', \array_slice($part1['value']['parts'], 0, -1));
					if (($moduleName !== '') && (! \in_array($moduleName, $this->includedModules, true))) {
						$this->includedModules[] = $moduleName;
						$this->justIncludedModulesOutput[] = (new Transpiler($moduleName, $this->includedModules, $this->definedMethods))->run();
					}
					$part2 = isset($blockItem['items'][2]) ? $blockItem['items'][2] : null;
					$alias = \ucfirst(\str_replace('&', '', $part2 !== null ? 
						$part2['value']['parts'][0]:
						$part1['value']['parts'][\count($part1['value']['parts']) - 1]));
					$lastPartValue = \array_pop($part1['value']['parts']);
					$ampPos = \strpos($lastPartValue, '&');
					if ($ampPos === false) {
						$methodLocation = null;
					} else
					if ($ampPos === 0) {
						$methodLocation = 'postfix';
					} else {
						$methodLocation = 'prefix';
					}
					$fullName = $this->nodeSymbolToOutIdentifier($part1, null);
					$this->symbolsToImport[$alias] = ['prefix' => $fullName !== '' ? $fullName . '__' : '', 'symbol' => \ucfirst(\str_replace('&', '', $lastPartValue))];
					$this->symbolsToImportMethodLocation[$alias] = $methodLocation;
				};
				if ($blockItem['items'][1]['type'] === Parser::T_BLOCK_PRIMARY) {
					foreach ($blockItem['items'][1]['items'] as $nodeSymbolImported) {
						$handleItem(new \ArrayObject([
							'items' => [null, $nodeSymbolImported]
						]));
					}
				} else {
					$handleItem($blockItem);
				}
			},
			'\'const' => function($nodeBlock) {
				$this->commitOutputLine('Global ' . $this->convertEvaluableBlock($nodeBlock['items'][1]) . ' = ' . $this->convertEvaluableBlock($nodeBlock['items'][2]));
			},
			'\'type' => function($nodeBlock) {
				$typeName = $this->nodeSymbolToOutIdentifier(
					$nodeBlock['items'][1], 
					\in_array($nodeBlock['items'][1]['value']['parts'][0], $this->symbolsToExport, true) ? $this->exportPrefix : $this->privatePrefix
				);
				$this->commitOutputLine(
					'Type ' . $typeName . 
					self::STR_EOL . \implode(self::STR_EOL, \array_map(function($item) {return "\t" . 'Field ' . $this->nodeSymbolToOutIdentifier($item, null, false);}, \array_slice($nodeBlock['items'], 2))));
				$this->commitOutputLine('End Type');
				$this->commitOutputLine('Global ' . $typeName . ' = ' . '1' . self::STR_EOL);
			},
			'\'tdelete' => function($nodeBlock) {
				$this->commitOutputLine('Delete ' . $this->convertEvaluableBlock($nodeBlock['items'][1]));
			},
			'\'method' => function($nodeBlock) {
				$nodeBlock['items'][1]['value']['interface'] = null; // !! omit interface to form method name without it, because lookups will not contain interface part
				$methodName = $this->nodeSymbolToOutIdentifier(
					$nodeBlock['items'][1], 
					\in_array($nodeBlock['items'][1]['value']['parts'][0], $this->symbolsToExport, true) ? $this->exportPrefix : $this->privatePrefix
				);
				$this->definedMethods[$methodName] = [
					'nodeBlock' => $nodeBlock,
					'args' => \array_map(function($item) {return $this->convertEvaluableBlock($item);}, \array_slice($nodeBlock['items'], 2)),
					'implementations' => []
				];
			},
			'\'function' => function($nodeBlock) {
				$functionName = $this->nodeSymbolToOutIdentifier(
					$nodeBlock['items'][1], 
					\in_array($nodeBlock['items'][1]['value']['parts'][0], $this->symbolsToExport, true) ? $this->exportPrefix : $this->privatePrefix
				);
				$lastItem = $nodeBlock['items'][\count($nodeBlock['items']) - 1];
				$methodImplName = null;
				if ($lastItem['type'] !== Parser::T_BLOCK_PRIMARY) {
					$methodImplName = $this->nodeSymbolToOutIdentifier($lastItem, null, false);
					if (! isset($this->definedMethods[$methodImplName])) {
						throw new \Exception($methodImplName . ' - ' . \json_encode(\array_keys($this->definedMethods)));
					}
					\array_push($this->definedMethods[$methodImplName]['implementations'], [
						'function' => $functionName,
						'thisArg' => $this->nodeSymbolToOutIdentifier($nodeBlock['items'][2], null, false)
					]);
				}
				if ($methodImplName !== null) {
					$this->commitOutputLine('; implementation of ' . $methodImplName);
				}
				$this->commitOutputLine(
					'Function ' . $functionName . 
					'(' . \implode(', ', \array_map(function($item) {return $this->convertEvaluableBlock($item);}, \array_slice($nodeBlock['items'], 2, $methodImplName === null ? -1 : -2))) . ')');
				$this->handleCallListBlock($nodeBlock['items'][\count($nodeBlock['items']) + ($methodImplName === null ? -1 : -2)]);
				$this->commitOutputLine('End Function' . self::STR_EOL);
			},
			'\'return' => function($nodeBlock) {
				$this->commitOutputLine('Return '  . $this->convertEvaluableBlock($nodeBlock['items'][1]));
			},
			'\'set' => function($nodeBlock) {
				$this->commitOutputLine(
					$this->nodeSymbolToOutIdentifier($nodeBlock['items'][1], null, true) . 
					' = ' . $this->convertEvaluableBlock($nodeBlock['items'][2]));
			},
			'\'if' => function($nodeBlock) {
				for ($iNode = 1; $iNode < \count($nodeBlock['items']); $iNode = $iNode + 2) {
					$condPart = $nodeBlock['items'][$iNode];
					$execPart = $nodeBlock['items'][$iNode + 1];
					if (($condPart['type'] === Parser::T_SYMBOL_SIMPLE) && ($condPart['value']['parts'][0] === '\'else')) {
						$this->commitOutputLine('Else');
					} else {
						$this->commitOutputLine(($iNode === 1 ? 'If' : 'Else If') . ' ' . $this->convertEvaluableBlock($condPart) . ' Then');
					}
					$this->handleCallListBlock($execPart);
				}
				$this->commitOutputLine('End If');
			},
			'\'while' => function($nodeBlock) {
				$condPart = $nodeBlock['items'][1];
				$execPart = $nodeBlock['items'][2];
				$this->commitOutputLine('While ' . $this->convertEvaluableBlock($condPart));
				$this->handleCallListBlock($execPart);
				$this->commitOutputLine('Wend');
			},
			'\'forstep' => function($nodeBlock) {
				$varPart = $nodeBlock['items'][1];
				$startValue = $nodeBlock['items'][2];
				$endValue = $nodeBlock['items'][3];
				$stepValue = $nodeBlock['items'][4];
				$execPart = $nodeBlock['items'][5];
				$this->commitOutputLine(
					'For ' . $this->nodeSymbolToOutIdentifier($varPart) . 
					' = ' . $this->convertEvaluableBlock($startValue) . 
					' To ' . $this->convertEvaluableBlock($endValue) . 
					' Step ' . $this->convertEvaluableBlock($stepValue));
				$this->handleCallListBlock($execPart);
				$this->commitOutputLine('Next');
			},
			'\'break' => function($nodeBlock) {
				$this->commitOutputLine('Exit');
			},
			'@@__callProc' => function($nodeBlock) {
				$this->commitOutputLine($this->convertEvaluableBlock($nodeBlock));
			}
		];
	}
	
	public function run() {
		$hereIsRoot = empty($this->includedModules); // empty($this->includedModules) < 2
		if ($hereIsRoot) {
			/*$this->commitOutputLine('Type TObjRef');
			$this->commitOutputLine("\t" . 'Field obj_handle%');
			$this->commitOutputLine("\t" . 'Field obj_typeId%');
			$this->commitOutputLine('End Type' . self::STR_EOL);*/
		}
		$this->handleCallListBlock($this->ast);
		if ($hereIsRoot) {
			foreach ($this->definedMethods as $methodName => $methodDescr) {
				$this->commitOutputLine('Function ' . $methodName . '(' . \implode(', ', $methodDescr['args']) . ')');
				$this->commitOutputLine("\t" . 'Select True');
				foreach ($methodDescr['implementations'] as $curImpl) {
					$typeName = \explode('.', $curImpl['thisArg'])[1];
					$objectCastPart = 'Object.' . $typeName . '(var_this)';
					$this->commitOutputLine("\t\t" . 'Case ' . $objectCastPart . ' <> Null');
					$this->commitOutputLine("\t\t\t" . 'Return ' . $curImpl['function'] . '(' . \implode(', ', \array_merge([$objectCastPart], \array_slice($methodDescr['args'], 1))) . ')');
				}
				$this->commitOutputLine("\t" . 'Default');
				$this->commitOutputLine("\t\t" . 'RuntimeError "Implementation not found."');
				$this->commitOutputLine("\t" . 'End Select');
				$this->commitOutputLine('End Function');
			}
			$this->commitOutputLine('; End of program');
		}
		return \implode('', \array_merge($this->justIncludedModulesOutput, $this->outputProgramParts));
	}
	
	private function handleStatementPrimaryBlock($nodeBlock) {
		if (($nodeBlock['items'][0]['type'] !== Parser::T_SYMBOL_SIMPLE) && ($nodeBlock['items'][0]['type'] !== Parser::T_SYMBOL_QUALIFIED)) {
			throw new \Exception('Evaluable functions or operators are not supported.');
		}
		$symbol = $nodeBlock['items'][0]['value']['parts'][0];
		$handlers = $this->statementOperatorHandlers;
		if (! isset($handlers[$symbol])) {
			$symbol = '@@__callProc';
		}
		$handlers[$symbol]($nodeBlock);
	}
	
	private function handleIndexBlock($nodeBlock) {
		
	}
	
	private function handleCallListBlock($nodeCallListBlock) {
		$this->curBlockLevel ++;
		foreach ($nodeCallListBlock['items'] as $curNodeBlock) {
			$this->handleStatementPrimaryBlock($curNodeBlock);
		}
		$this->curBlockLevel --;
	}
	
	private function convertEvaluableBlock($nodeEvaluableBlock) {
		if ($nodeEvaluableBlock['type'] === Parser::T_STRING) {
			return '"' . \str_replace('"', '" + Chr(34) + "', $nodeEvaluableBlock['value']) . '"';
		} else
		if ($nodeEvaluableBlock['type'] === Parser::T_NUMBER_INTEGER) {
			return $nodeEvaluableBlock['value'];
		} else
		if ($nodeEvaluableBlock['type'] === Parser::T_NUMBER_FLOAT) {
			return $nodeEvaluableBlock['value'];
		} else
		if ($nodeEvaluableBlock['type'] === Parser::T_SYMBOL_SIMPLE) {
			$symbol = $nodeEvaluableBlock['value']['parts'][0];
			if ($symbol === '\'true') {
				return 'True';
			} else
			if ($symbol === '\'false') {
				return 'False';
			} else
			if ($symbol === '\'null') {
				return '0';
			} else
			if ($symbol === '\'%null') {
				return 'Null';
			} else {
				return $this->nodeSymbolToOutIdentifier($nodeEvaluableBlock, null, true);
			}
		} else
		if ($nodeEvaluableBlock['type'] === Parser::T_SYMBOL_QUALIFIED) {
			return $this->nodeSymbolToOutIdentifier($nodeEvaluableBlock, null, true);
		} else
		if ($nodeEvaluableBlock['type'] === Parser::T_BLOCK_PRIMARY) {
			$operands = \array_map(function($item) {return $this->convertEvaluableBlock($item);}, \array_slice($nodeEvaluableBlock['items'], 1));
			$operatorSymbol = $nodeEvaluableBlock['items'][0]['value']['parts'][0];
			if (isset($this->operators[$operatorSymbol])) {
				$operatorTargetSymbol = $this->operators[$operatorSymbol];
				if (\count($operands) === 1) {
					\array_unshift($operands, '');
				}
				return '(' . \implode(' ' . $operatorTargetSymbol . ' ', $operands) . ')';
			} else {
				return $this->nodeSymbolToOutIdentifier($nodeEvaluableBlock['items'][0], null, true) . 
					'(' . \implode(', ', $operands) . ')';
			}
		}
	}
	
	private function nodeSymbolToOutIdentifier($nodeSymbol, $prefix = null, $defaultToLocalPrefix = false) {
		$result = null;
		foreach ($nodeSymbol['value']['parts'] as &$curPart) {
			$curPart = \ucfirst($curPart);
			$curPart = \str_replace('-', '_', $curPart);
		}
		$firstChar = \substr($nodeSymbol['value']['parts'][0], 0, 1);
		if (($firstChar === '\'') || ($firstChar === '$')) {
			$result = \str_replace('$', 'var_', \implode('\\', $nodeSymbol['value']['parts']));
		} else {
			$partCount = \count($nodeSymbol['value']['parts']);
			if (($prefix === null) && ($partCount > 1) && (isset($this->symbolsToImport[$nodeSymbol['value']['parts'][$partCount - 2]]))) {
				if (\strpos($nodeSymbol['value']['parts'][$partCount - 1], '&') === false) {
					$methodLocation = $this->symbolsToImportMethodLocation[$nodeSymbol['value']['parts'][$partCount - 2]];
					if ($methodLocation === 'prefix') {
						$nodeSymbol['value']['parts'][$partCount - 1] = '&' . $nodeSymbol['value']['parts'][$partCount - 1];
					} else
					if ($methodLocation === 'postfix') {
						$nodeSymbol['value']['parts'][$partCount - 1] = $nodeSymbol['value']['parts'][$partCount - 1] . '&';
					}
				}
			}
			if (($partCount > 1) && (\strpos($nodeSymbol['value']['parts'][$partCount - 1], '&') !== false)) {
				$lastPart = \array_pop($nodeSymbol['value']['parts']);
				$preLastPart = \array_pop($nodeSymbol['value']['parts']);
				if (($prefix === null) && (isset($this->symbolsToImport[$preLastPart]))) {
					$prefix = $this->symbolsToImport[$preLastPart]['prefix'];
					$preLastPart = $this->symbolsToImport[$preLastPart]['symbol'];
				} else
				if (($prefix === null) && $defaultToLocalPrefix) {
					$prefix = $this->privatePrefix;
				}
				$lastPartWord = \str_replace('&', '', $lastPart);
				$lastPart = \str_replace($lastPartWord, \ucfirst($lastPartWord), $lastPart);
				\array_push($nodeSymbol['value']['parts'], \str_replace('&', $preLastPart, $lastPart));
			} else {
				if (($prefix === null) && (isset($this->symbolsToImport[$nodeSymbol['value']['parts'][0]]))) {
					$prefix = $this->symbolsToImport[$nodeSymbol['value']['parts'][0]]['prefix'];
					$nodeSymbol['value']['parts'][0] = $this->symbolsToImport[$nodeSymbol['value']['parts'][0]]['symbol']; // \count($nodeSymbol['value']['parts']) - 1
				} else
				if (($prefix === null) && $defaultToLocalPrefix) {
					$prefix = $this->privatePrefix;
				}
			}
			$result = \implode('__', $nodeSymbol['value']['parts']);
			if ($prefix !== null) {
				$result = $prefix . $result;
			}
		}
		if ($nodeSymbol['value']['interface'] !== null) {
			$typeMarker = '';
			switch ($nodeSymbol['value']['interface']) {
				case 'int': 
					$typeMarker = '%';
					break;
				case 'bool': 
					$typeMarker = '%';
					break;
				case 'float':
					$typeMarker = '#';
					break;
				case 'str': 
					$typeMarker = '$';
					break;
				case 'ref':
					$typeMarker = '%';
					break;
				case 'void':
					$typeMarker = '%';
					break;
				default: 
					if (\substr($nodeSymbol['value']['interface'], 0, 1) === '%') {
						$varType = \substr($nodeSymbol['value']['interface'], 1);
						if (isset($this->symbolsToImport[$varType])) {
							$varType = $this->symbolsToImport[$varType]['prefix'] . $this->symbolsToImport[$varType]['symbol'];
						} else {
							$varType = $this->privatePrefix . $varType;
						}
						$typeMarker = '.' . $varType;
					}  else {
						$typeMarker = '%';
					}
					break;
			}
			$result = $result . $typeMarker;
		}
		return $result;
	}
	
	private function commitOutputLine($outputLine) {
		$this->outputProgramParts[] = \str_repeat("\t", \max($this->curBlockLevel, 0)) . $outputLine . self::STR_EOL; 
	}
}
