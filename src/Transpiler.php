<?php

namespace SLBBTools;

require_once './Lexer.php';
require_once './Parser.php';
require_once './Translator.php';

class Transpiler {
	
	private $sourceModuleName;
	
	public function __construct($sourceModuleName) {
		$this->sourceModuleName = $sourceModuleName;
	}
	
	public function run() {
		$sourcePath = $_SERVER['DOCUMENT_ROOT'] . '/' . \str_replace('.', '/', $this->sourceModuleName) . '.slbb';
		$lexed = (new Lexer(\file_get_contents($sourcePath)))->run(); // \file_put_contents($sourcePath . '_lexed.json', \json_encode($lexed, \JSON_UNESCAPED_UNICODE));	
		$parsed = (new Parser($lexed))->run(); // \file_put_contents($sourcePath . '_parsed.json', \json_encode($parsed, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
		$transpiled = (new Translator($parsed))->run(); // \file_put_contents($sourcePath . '_out.bb', $transpiled);
		return $transpiled;
	}
	
}
