<?php

namespace SLBBTools;

class Transpiler {
	
	private $ast;
	private $exportPrefix;
	private $privatePrefix;
	private $symbolsToExport; // ...['values']['parts'] only (not the whole node)
	private $symbolsToImport; // alias => full name
	private $outputProgramParts;
	
	public function __construct($ast) {
		$this->ast = $ast;
		$this->exportPrefix = '';
		$this->privatePrefix = '';
		$this->symbolsToExport = [];
		$this->symbolsToImport = [];
		$this->outputProgramParts = [];
	}
	
	public function run() {
		$this->handleBlock($this->ast);
		return $this->generateOutput();
	}
	
	private function handleBlock($nodeBlock) {
		switch ($nodeBlock['type']) {
			case Parser::T_BLOCK_ROOT:
				return $this->handleRootBlock($nodeBlock);
				break;
			case Parser::T_BLOCK_PRIMARY:
				return $this->handlePrimaryBlock($nodeBlock);
				break;
			case Parser::T_BLOCK_INDEX:
				return $this->handleIndexBlock($nodeBlock);
				break;
			default: 
				throw new \Exception('wrong node type at block handle level');
				break;
		}
	}
	
	private function handleRootBlock($nodeBlock) {
		foreach ($nodeBlock['items'] as $blockItem) {
			if ($blockItem['type'] === Parser::T_BLOCK_PRIMARY) {
				if ($blockItem['items'][0]['value']['parts'][0] === '@module') {
					$this->handleModuleDirective($blockItem['items'][1], $blockItem['items'][2]);
				} else
				if ($blockItem['items'][0]['value']['parts'][0] === '@use') {
					$alias = isset($blockItem['items'][2]) ? 
						$blockItem['items'][2]['value']['parts'][0]:
						$blockItem['items'][1]['value']['parts'][\count($blockItem['items'][1]['value']['parts']) - 1];
					$fullName = $this->nodeSymbolToOutIdentifier($blockItem['items'][1], null);
					$this->symbolsToImport[$alias] = \str_replace('__' . $alias, '', $fullName);
				} else {
					$this->handlePrimaryBlock($blockItem);
				}
			} else {
				throw new \Exception('Only primary blocks allowed at root level.');
			}
		}
	}
	
	private function nodeSymbolToOutIdentifier($nodeSymbol, $prefix = null) {
		$result = null;
		foreach ($nodeSymbol['value']['parts'] as &$curPart) {
			$curPart = \ucfirst($curPart);
		}
		$firstChar = \substr($nodeSymbol['value']['parts'][0], 0, 1);
		if (($firstChar === '@') || ($firstChar === '$')) {
			$result = \str_replace('$', '', \implode('\\', $nodeSymbol['value']['parts']));
		} else {
			$partCount = \count($nodeSymbol['value']['parts']);
			if (($partCount > 1) && (\strpos($nodeSymbol['value']['parts'][$partCount - 1], '*') !== false)) {
				$lastPart = \array_pop($nodeSymbol['value']['parts']);
				$preLastPart = \array_pop($nodeSymbol['value']['parts']);
				if (($prefix === null) && (isset($this->symbolsToImport[$preLastPart]))) {
					$prefix = $this->symbolsToImport[$preLastPart];
				}
				$lastPartWord = \str_replace('*', '', $lastPart);
				$lastPart = \str_replace($lastPartWord, \ucfirst($lastPartWord), $lastPart);
				\array_push($nodeSymbol['value']['parts'], \str_replace('*', $preLastPart, $lastPart));
			} else {
				if (($prefix === null) && (isset($this->symbolsToImport[$nodeSymbol['value']['parts'][0]]))) {
					$prefix = $this->symbolsToImport[$nodeSymbol['value']['parts'][0]];
				}
			}
			$result = \implode('__', $nodeSymbol['value']['parts']);
			if ($prefix !== null) {
				$result = $prefix . '__' . $result;
			}
		}
		if ($nodeSymbol['value']['interface'] !== null) {
			$typeMarker = '';
			switch ($nodeSymbol['value']['interface']) {
				case '@int': 
					$typeMarker = '%';
					break;
				case '@bool': 
					$typeMarker = '%';
					break;
				case '@float':
					$typeMarker = '#';
					break;
				case '@str': 
					$typeMarker = '$';
					break;
				case '@ref':
					$typeMarker = '%';
					break;
				case '@void':
					$typeMarker = '%';
					break;
				default: 
					if (\substr($nodeSymbol['value']['interface'], 0, 1) === '%') {
						$varType = \substr($nodeSymbol['value']['interface'], 1);
						if (isset($this->symbolsToImport[$varType])) {
							$varType = $this->symbolsToImport[$varType] . '__' . $varType;
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
	
	private function handlePrimaryBlock($nodeBlock) {
		if (($nodeBlock['items'][0]['type'] === Parser::T_SYMBOL_SIMPLE) || 
			($nodeBlock['items'][0]['type'] === Parser::T_SYMBOL_QUALIFIED)) {
			
			$symbol = $nodeBlock['items'][0]['value']['parts'][0];
			if ($symbol === '@func') {
				$this->outputProgramParts[] = 
					'Function ' . 
					$this->nodeSymbolToOutIdentifier(
						$nodeBlock['items'][1], 
						\in_array($nodeBlock['items'][1]['value']['parts'], $this->symbolsToExport, true) ? 
							$this->exportPrefix : 
							$this->privatePrefix
					) . 
					'(' . \implode(', ', \array_map(function($item) {return $this->convertEvaluableBlock($item);}, \array_slice($nodeBlock['items'], 2, -1))) . ')' .
					"\r\n";
				$this->handleCallListBlock($nodeBlock['items'][\count($nodeBlock['items']) - 1]);
				$this->outputProgramParts[] = 'End Function' . "\r\n\r\n";
			} else 
			if ($symbol === '@let') {
				$this->outputProgramParts[] = 
					"\t" . $this->nodeSymbolToOutIdentifier($nodeBlock['items'][1]) . 
					' = ' . $this->convertEvaluableBlock($nodeBlock['items'][2]) . "\r\n";
			} else 
			if ($symbol === '@ret') {
				$this->outputProgramParts[] = 
					"\tReturn "  . $this->convertEvaluableBlock($nodeBlock['items'][1]) . "\r\n";
			} else {
				$this->outputProgramParts[] = "\t" . $this->convertEvaluableBlock($nodeBlock) . "\r\n";
			}
		}
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
			if ($nodeEvaluableBlock['value']['parts'][0] === '@true') {
				return 'True';
			} else
			if ($nodeEvaluableBlock['value']['parts'][0] === '@false') {
				return 'False';
			} else
			if ($nodeEvaluableBlock['value']['parts'][0] === '@null') {
				return '0';
			} else
			if ($nodeEvaluableBlock['value']['parts'][0] === '%null') {
				return 'Null';
			} else {
				return $this->nodeSymbolToOutIdentifier($nodeEvaluableBlock);
			}
		} else
		if ($nodeEvaluableBlock['type'] === Parser::T_SYMBOL_QUALIFIED) {
			return $this->nodeSymbolToOutIdentifier($nodeEvaluableBlock);
		} else
		if ($nodeEvaluableBlock['type'] === Parser::T_BLOCK_PRIMARY) {
			return $this->nodeSymbolToOutIdentifier($nodeEvaluableBlock['items'][0]) . 
				'(' . \implode(', ', \array_map(function($item) {return $this->convertEvaluableBlock($item);}, \array_slice($nodeEvaluableBlock['items'], 1))) . ')';
		}
	}
	
	private function handleCallListBlock($nodeCallListBlock) {
		foreach ($nodeCallListBlock['items'] as $curNodeBlock) {
			$this->handlePrimaryBlock($curNodeBlock);
		}
	}
	
	private function handleIndexBlock($nodeBlock) {
		
	}
	
	private function handleModuleDirective($nodeSymbolModuleName, $nodeSymbolModuleExports) {
		$this->exportPrefix = $this->nodeSymbolToOutIdentifier($nodeSymbolModuleName, null);
		$this->privatePrefix = 'local__'. \substr(\uniqid('', true), 15);
		foreach ($nodeSymbolModuleExports['items'] as $nodeSymbolExported) {
			$this->symbolsToExport[] = $nodeSymbolExported['value']['parts'];
		}
		
		$this->outputProgramParts[] = '; module prefix definition: ' . $this->exportPrefix . "\r\n\r\n";;
	}
	
	private function generateOutput() {
		return \implode('', $this->outputProgramParts);
	}
	
}
