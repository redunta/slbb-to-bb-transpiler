<?php

namespace SLBBTools;

class Lexer {
	
	const T_UNKNOWN = 0;
	const T_PRIMARY_BLOCK_OPEN = 10;
	const T_PRIMARY_BLOCK_CLOSE = 20;
	const T_INDEX_BLOCK_OPEN = 30;
	const T_INDEX_BLOCK_CLOSE = 40;
	const T_STRING = 50;
	const T_NUMBER = 60;
	const T_SYMBOL = 70;
	const T_DOT_SEPARATOR = 90;
	const T_BACKSLASH_SEPARATOR = 91;
	const T_SEMICOLON_SEPARATOR = 92;
	const T_COMMENT = 200;
	const T_END_OF_LINE = 100;
	
	const SYM_DOUBLE_QUOTE = '"';
	const SYM_BACKSLASH = '\\';
	
	public function __construct($sourceText) {
		$this->sourceText = $sourceText;
	}
	
	private $currentTerminalSymbols;
	private $currentTerminalSymbolsEffective;
	private $prevPeepedSymbol;
	private $spaceSymbols;
	private $currentSourceSymbolIndex;
	private $sourceText;
	private $sourceTextLength;
	private $tokens;
	private $constants;
	
	private function initialize($sourceText) {
		$this->constants = \array_flip((new \ReflectionClass(__CLASS__))->getConstants());
		$this->currentSourceSymbolIndex = 0;
		$this->prevPeepedSymbol = '';
		$this->sourceText = $sourceText;
		$this->sourceTextLength = \strlen($this->sourceText);
		$this->currentTerminalSymbols = [];
		$this->pushTerminalSymbols([' ', '(', ')', '[', ']', ':', '.', '\\', ';', "\r", "\n"]);
		$this->spaceSymbols = [' ', "\r", "\n", "\t"];
		$this->tokens = [];
	}
	
	private function getTokens() {
		return $this->tokens;
	}
	
	private function updateTerminalSymbolsEffective() {
		$this->currentTerminalSymbolsEffective = \call_user_func_array('array_merge', $this->currentTerminalSymbols);
	}
	
	private function pushTerminalSymbols($symbols) {
		\array_push($this->currentTerminalSymbols, $symbols);
		$this->updateTerminalSymbolsEffective();
	}
	
	private function popTerminalSymbols() {
		$result = \array_pop($this->currentTerminalSymbols);
		$this->updateTerminalSymbolsEffective();
		return $result;
	}
	
	private function peepNextSymbol() {
		$result = '';
		for ($iSym = $this->currentSourceSymbolIndex; $iSym < $this->sourceTextLength; $iSym ++) {
			$curSym = $this->sourceText[$iSym];
			if (! \in_array($curSym, $this->spaceSymbols, true)) {
				$result = $curSym;
				$this->currentSourceSymbolIndex = $iSym;
				break;
			}
		}
		return $this->prevPeepedSymbol = $result;
	}
	
	private function skipNextSymbol() {
		$this->currentSourceSymbolIndex ++;
	}
	
	private function peekPrevSymbol() {
		return $this->prevPeepedSymbol;
	}
	
	private function nextTokenValue($overrideDelimiters = null, $escapeSymbol = null, $isInside = false) {
		$overrideDelimiters = $overrideDelimiters !== null ? $overrideDelimiters : $this->currentTerminalSymbolsEffective;
		$result = null;
		for ($localSTIndex = $this->currentSourceSymbolIndex; 
			$localSTIndex < $this->sourceTextLength;
			$localSTIndex ++) {
			
			$curSymbol = $this->sourceText[$localSTIndex];
			if (($escapeSymbol !== null) && ($curSymbol === $escapeSymbol)) {
				$localSTIndex ++;
				continue;
			}
			if (\in_array($curSymbol, $overrideDelimiters, true)) {
				if ($localSTIndex === $this->currentSourceSymbolIndex) {
					if ($escapeSymbol === null) {
						$result = $curSymbol;
						$this->currentSourceSymbolIndex = $localSTIndex + 1;
					} else {
						continue;
					}
				} else {
					$result = \substr(
						$this->sourceText, 
						$this->currentSourceSymbolIndex, 
						$localSTIndex - $this->currentSourceSymbolIndex);
					$this->currentSourceSymbolIndex = $localSTIndex + (($escapeSymbol === null) ? 0 : 1);
				}
				break;
			}
		}
		if ($result === null) {
			$result = \substr($this->sourceText, $this->currentSourceSymbolIndex);
			$this->currentSourceSymbolIndex = $this->sourceTextLength;
		}
		if ($escapeSymbol && (\strlen($result) > 0) && \in_array($result[0], $overrideDelimiters, true)) {
			$result = \substr($result, 1);
		}
		return ((! $isInside) && \in_array($result, $this->spaceSymbols, true)) ? $this->nextTokenValue($overrideDelimiters, $escapeSymbol, true) : $result; // ($result !== '"') && 
	}
	
	private function commitToken($tokenType, $tokenValue = null) {
		\array_push($this->tokens, [
			'tokenType' => $tokenType, 
			'tokenTypeName' => $this->constants[$tokenType],
			'tokenValue' => $tokenValue
		]);
	}
	
	public function run() {
		$this->initialize($this->sourceText);
		while (($prevPeepedSymbol = $this->peekPrevSymbol() ?: ' ') && (($peepedSymbol = $this->peepNextSymbol()) !== '')) {
			if ($peepedSymbol === ';') {
				$curTokenValue = $this->nextTokenValue(["\r", "\n"]);
				$this->commitToken(self::T_COMMENT, \trim($curTokenValue, ' ;'));
			} else
			if ($peepedSymbol === '#') {
				$this->skipNextSymbol();
			} else 
			if ($peepedSymbol === '|') {
				if ($prevPeepedSymbol === '#') {
					$curTokenValue = $this->nextTokenValue(['|'], true);
					$this->commitToken(self::T_COMMENT, \trim($curTokenValue));
				} else {
					$this->skipNextSymbol();
				}
			} else
			if ($peepedSymbol === self::SYM_DOUBLE_QUOTE) {
				$curTokenValue = $this->nextTokenValue([self::SYM_DOUBLE_QUOTE], self::SYM_BACKSLASH);
				$tempString = $curTokenValue;
				$tempString = \str_replace(self::SYM_BACKSLASH . self::SYM_DOUBLE_QUOTE, self::SYM_DOUBLE_QUOTE, $tempString);
				$tempString = \str_replace(self::SYM_BACKSLASH . self::SYM_BACKSLASH, self::SYM_BACKSLASH, $tempString);
				$this->commitToken(self::T_STRING, $tempString);
			} else 
			if (\ctype_digit($peepedSymbol)) {
				$curTokenValue = $this->nextTokenValue(\array_filter($this->currentTerminalSymbolsEffective, function($value) {
					return $value !== '.';
				}));
				if (\ctype_digit(\str_replace('.', '', $curTokenValue))) {
					$this->commitToken(self::T_NUMBER, $curTokenValue);
				}
			} else {
				$curTokenValue = $this->nextTokenValue();
				if ($curTokenValue === '.') {
					$this->commitToken(self::T_DOT_SEPARATOR);
				} else
				if ($curTokenValue === '\\') {
					$this->commitToken(self::T_BACKSLASH_SEPARATOR);
				} else
				if ($curTokenValue === ':') {
					$this->commitToken(self::T_SEMICOLON_SEPARATOR);
				} else
				if ($curTokenValue === '(') {
					$this->commitToken(self::T_PRIMARY_BLOCK_OPEN);
				} else 
				if ($curTokenValue === ')') {
					$this->commitToken(self::T_PRIMARY_BLOCK_CLOSE);
				} else
				if ($curTokenValue === '[') {
					$this->commitToken(self::T_INDEX_BLOCK_OPEN);
				} else 
				if ($curTokenValue === ']') {
					$this->commitToken(self::T_INDEX_BLOCK_CLOSE);
				} else 
				if (isset($curTokenValue[0]) && (! \ctype_digit($curTokenValue[0]))) {
					$this->commitToken(self::T_SYMBOL, $curTokenValue);
				} else {
					$this->commitToken(self::T_UNKNOWN, $curTokenValue);
				}
			}
		}
		return $this->getTokens();
	}
	
}
