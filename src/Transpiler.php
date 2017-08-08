<?php

namespace SLBBTools;

require_once './Lexer.php';
require_once './Parser.php';
require_once './Translator.php';

class Transpiler {
	
	private $sourceModuleName;
	private $includedModules;
	private $definedMethods;
	
	public function __construct($sourceModuleName, &$includedModules = [], &$definedMethods = []) {
		$this->sourceModuleName = $sourceModuleName;
		$this->includedModules = &$includedModules;
		$this->definedMethods = &$definedMethods;
	}
	
	public function run() {
		$sourcePath = $_SERVER['DOCUMENT_ROOT'] . '/' . \str_replace('.', '/', $this->sourceModuleName) . '.slbb';
		$sourceCode = \file_get_contents($sourcePath);
		return (new Translator((new Parser((new Lexer($sourceCode))->run()))->run(), $this->includedModules, $this->definedMethods))->run();
	}
	
}
