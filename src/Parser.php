<?php

namespace SLBBTools;

class Parser {
	
	const T_BLOCK_ROOT = 'block_root';
	const T_BLOCK_PRIMARY = 'block_primary';
	const T_BLOCK_INDEX = 'block_index';
	const T_NUMBER_FLOAT = 'number_float';
	const T_NUMBER_INTEGER = 'number_integer';
	const T_STRING = 'string';
	const T_SYMBOL_SIMPLE = 'symbol_simple';
	const T_SYMBOL_QUALIFIED = 'symbol_qualified';
	
	private $sourceTokens;
	private $ast;
	private $curPrimaryBlockNode;
	
	public function __construct($tokens) {
		$this->sourceTokens = $tokens;
		$this->ast = new \ArrayObject([
			'type' => self::T_BLOCK_ROOT,
			'items' => [],
			'parent' => null
		]);
		$this->curPrimaryBlockNode = $this->ast;
	}
	
	private function beginBlock($type) {
		$result = new \ArrayObject([
			'type' => $type,
			'items' => [],
			'parent' => $this->curPrimaryBlockNode
		]);
		\array_push($this->curPrimaryBlockNode['items'], $result);
		$this->curPrimaryBlockNode = $result;
		return $result;
	}
	
	private function endBlock($type = null) {
		if (($type !== null) && ($this->curPrimaryBlockNode['type'] !== $type)) {
			throw new \Exception('Block type mismatch.');
		}
		$result = $this->curPrimaryBlockNode;
		$this->curPrimaryBlockNode = $this->curPrimaryBlockNode['parent'];
		return $result;
	}
	
	private function addBlockItem($itemType, $item) {
		\array_push($this->curPrimaryBlockNode['items'], new \ArrayObject([
			'type' => $itemType,
			'value' => $item
		]));
	}
	
	private function removeBlockItem() {
		return \array_pop($this->curPrimaryBlockNode['items']);
	}
	
	public function run() {
		$dotIsEncountered = false;
		$semicolonsEncountered = 0;
		foreach ($this->sourceTokens as $curToken) {
			if ($curToken['tokenType'] === Lexer::T_PRIMARY_BLOCK_OPEN) {
				$this->beginBlock(self::T_BLOCK_PRIMARY);
			} else
			if ($curToken['tokenType'] === Lexer::T_PRIMARY_BLOCK_CLOSE) {
				$this->endBlock(self::T_BLOCK_PRIMARY);
			} else
			if ($curToken['tokenType'] === Lexer::T_INDEX_BLOCK_OPEN) {
				$this->beginBlock(self::T_BLOCK_INDEX);
			} else
			if ($curToken['tokenType'] === Lexer::T_INDEX_BLOCK_CLOSE) {
				$this->endBlock(self::T_BLOCK_INDEX);
			} else
			if ($curToken['tokenType'] === Lexer::T_STRING) {
				$this->addBlockItem(self::T_STRING, $curToken['tokenValue']);
			} else
			if ($curToken['tokenType'] === Lexer::T_NUMBER) {
				$this->addBlockItem(\strpos($curToken['tokenValue'], '.') !== false ? self::T_NUMBER_FLOAT : self::T_NUMBER_INTEGER, $curToken['tokenValue']);
			} else
			if ($curToken['tokenType'] === Lexer::T_DOT_SEPARATOR) {
				$dotIsEncountered = true;
			} else
			if (($semicolonsEncountered === 2) && ($curToken['tokenType'] === Lexer::T_SYMBOL)) {
				$lastSymbolDescr = $this->removeBlockItem();
				$this->addBlockItem(self::T_SYMBOL_QUALIFIED, [
					'parts' => $lastSymbolDescr['value']['parts'],
					'interface' => $curToken['tokenValue']
				]);
				$semicolonsEncountered = 0;
			} else
			if ((! $dotIsEncountered) && ($curToken['tokenType'] === Lexer::T_SYMBOL)) {
				$this->addBlockItem(self::T_SYMBOL_SIMPLE, [
					'parts' => [$curToken['tokenValue']],
					'interface' => null
				]);
			} else
			if ($dotIsEncountered && ($curToken['tokenType'] === Lexer::T_SYMBOL)) {
				$lastSymbolDescr = $this->removeBlockItem();
				if (($lastSymbolDescr['type'] !== self::T_SYMBOL_SIMPLE) && ($lastSymbolDescr['type'] !== self::T_SYMBOL_QUALIFIED)) {
					throw new \Exception('Symbol expected before dot. Token given: ' . $lastSymbolDescr['type']);
				}
				\array_push($lastSymbolDescr['value']['parts'], $curToken['tokenValue']);
				$this->addBlockItem(self::T_SYMBOL_QUALIFIED, [
					'parts' => $lastSymbolDescr['value']['parts'],
					'interface' => $lastSymbolDescr['value']['interface']
				]);
				$dotIsEncountered = false;
			} else 
			if ($curToken['tokenType'] === Lexer::T_SEMICOLON_SEPARATOR) {
				if ($semicolonsEncountered < 2) {
					$semicolonsEncountered ++;
				} else {
					throw new \Exception('Too many semicolons.');
				}
			}
		}
		return $this->ast;
	}
	
}
