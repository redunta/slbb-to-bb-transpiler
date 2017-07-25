<?php

namespace SLBBTools;

class Translator {
	
	const STR_EOL = "\r\n";
	
	private $ast;
	private $exportPrefix;
	private $privatePrefix;
	private $symbolsToExport; // ...['values']['parts'] only (not the whole node)
	private $symbolsToImport; // alias => full name without last part
	private $outputProgramParts;
	private $operators;
	private $statementOperatorHandlers;
	private $curBlockLevel;
	
	public function __construct($ast) {
		$this->ast = $ast;
		$this->exportPrefix = '';
		$this->privatePrefix = '';
		$this->symbolsToExport = [];
		$this->symbolsToImport = [];
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
			'\'tafter' => 'After'
			// ! добавить всякие +1, -1, ? (тернарный иф)
		];
		
		$this->statementOperatorHandlers = [
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
					$part2 = isset($blockItem['items'][2]) ? $blockItem['items'][2] : null;
					$alias = $part2 !== null ? 
						$part2['value']['parts'][0]:
						$part1['value']['parts'][\count($part1['value']['parts']) - 1];
					$lastPartValue = \array_pop($part1['value']['parts']);
					$fullName = $this->nodeSymbolToOutIdentifier($part1, null);
					$this->symbolsToImport[\ucfirst($alias)] = [ 'prefix' => $fullName !== '' ? $fullName . '__' : '', 'symbol' => \ucfirst($lastPartValue)];
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
			'\'function' => function($nodeBlock) {
				$this->commitOutputLine(
					'Function ' . 
					$this->nodeSymbolToOutIdentifier(
						$nodeBlock['items'][1], 
						\in_array($nodeBlock['items'][1]['value']['parts'][0], $this->symbolsToExport, true) ? $this->exportPrefix : $this->privatePrefix
					) . 
					'(' . \implode(', ', \array_map(function($item) {return $this->convertEvaluableBlock($item);}, \array_slice($nodeBlock['items'], 2, -1))) . ')');
				$this->handleCallListBlock($nodeBlock['items'][\count($nodeBlock['items']) - 1]);
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
		$this->handleCallListBlock($this->ast);
		return \implode('', $this->outputProgramParts);
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
			if (\in_array($operatorSymbol, $this->operators, true)) {
				if (\count($operands) === 1) {
					\array_unshift($operands, '');
				}
				return '(' . \implode(' ' . $operatorSymbol . ' ', $operands) . ')';
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
		}
		$firstChar = \substr($nodeSymbol['value']['parts'][0], 0, 1);
		if (($firstChar === '\'') || ($firstChar === '$')) {
			$result = \str_replace('$', 'var_', \implode('\\', $nodeSymbol['value']['parts']));
		} else {
			$partCount = \count($nodeSymbol['value']['parts']);
			if (($partCount > 1) && (\strpos($nodeSymbol['value']['parts'][$partCount - 1], '*') !== false)) {
				$lastPart = \array_pop($nodeSymbol['value']['parts']);
				$preLastPart = \array_pop($nodeSymbol['value']['parts']);
				if (($prefix === null) && (isset($this->symbolsToImport[$preLastPart]))) {
					$prefix = $this->symbolsToImport[$preLastPart]['prefix'];
					$preLastPart = $this->symbolsToImport[$preLastPart]['symbol'];
				} else
				if (($prefix === null) && $defaultToLocalPrefix) {
					$prefix = $this->privatePrefix;
				}
				$lastPartWord = \str_replace('*', '', $lastPart);
				$lastPart = \str_replace($lastPartWord, \ucfirst($lastPartWord), $lastPart);
				\array_push($nodeSymbol['value']['parts'], \str_replace('*', $preLastPart, $lastPart));
			} else {
				if (($prefix === null) && (isset($this->symbolsToImport[$nodeSymbol['value']['parts'][0]]))) {
					$prefix = $this->symbolsToImport[$nodeSymbol['value']['parts'][0]]['prefix'];
					$nodeSymbol['value']['parts'][\count($nodeSymbol['value']['parts']) - 1] = $this->symbolsToImport[$nodeSymbol['value']['parts'][0]]['symbol'];
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
		$this->outputProgramParts[] = \str_repeat("\t", $this->curBlockLevel) . $outputLine . self::STR_EOL; 
	}
}
