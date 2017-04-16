<?php

declare(strict_types = 1);

namespace LanguageServer\Tests;

use Exception;
use LanguageServer\Index\Index;
use LanguageServer\ParserKind;
use LanguageServer\ParserResourceFactory;
use LanguageServer\PhpDocument;
use phpDocumentor\Reflection\DocBlockFactory;
use PHPUnit\Framework\TestCase;
use LanguageServer\ClientHandler;
use LanguageServer\Protocol\Message;
use AdvancedJsonRpc;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sabre\Event\Loop;
use Microsoft\PhpParser as Tolerant;

class ValidationTest extends TestCase
{
    public function frameworkErrorProvider() {
        $totalSize = 0;
        $frameworks = glob(__DIR__ . "/../../validation/frameworks/*", GLOB_ONLYDIR);

        $testProviderArray = array();
        foreach ($frameworks as $frameworkDir) {
            $frameworkName = basename($frameworkDir);
            if ($frameworkName !== "broken") {
//                continue;
            }
            $iterator = new RecursiveDirectoryIterator(__DIR__ . "/../../validation/frameworks/" . $frameworkName);

            foreach (new RecursiveIteratorIterator($iterator) as $file) {
                if (strpos(\strrev((string)$file), \strrev(".php")) === 0
//                    && strpos((string)$file, "taxonomy.php")!== false
                ) {
                    if ($file->getSize() < 100000) {
                        $testProviderArray[$frameworkName . "::" . $file->getBasename()] = [$file->getPathname(), $frameworkName];
                    }
                }
            }
        }
        if (count($testProviderArray) === 0) {
            throw new Exception("ERROR: Validation testsuite frameworks not found - run `git submodule update --init --recursive` to download.");
        }
        return $testProviderArray;
    }

    /**
     * @group validation
     * @dataProvider frameworkErrorProvider
     */
    public function testFramworkErrors($testCaseFile, $frameworkName) {
        $fileContents = file_get_contents($testCaseFile);
        
        $parser = ParserResourceFactory::getParser();
        $docBlockFactory = DocBlockFactory::createInstance();
        $index = new Index;
        $definitionResolver = ParserResourceFactory::getDefinitionResolver($index);

        $directory = __DIR__ . "/output/$frameworkName/";
        $outFile = $directory . basename($testCaseFile);

        try {
            $document = new PhpDocument($testCaseFile, $fileContents, $index, $parser, $docBlockFactory, $definitionResolver);
        } catch (\Exception $e) {
            if (!file_exists($dir = __DIR__ . "/output")) {
                mkdir($dir);
            }
            if (!file_exists($directory)) {
                mkdir($directory);
            }
            file_put_contents($outFile, $fileContents);
            $this->fail((string)$e);
        }

        $this->assertNotNull($document->getStmts());

        if (file_exists($outFile)) {
            unlink($outFile);
        }
        // echo json_encode($parser->getErrors($sourceFile));
    }

    private $index = [];

    private function getIndex($kind, $frameworkName) {
        if (!isset($this->index[$kind][$frameworkName])) {
            $this->index[$kind][$frameworkName] = new Index();
        }
        return $this->index[$kind][$frameworkName];
    }

    /**
     * @group validation
     * @dataProvider frameworkErrorProvider
     */
    public function testDefinitionErrors($testCaseFile, $frameworkName) {
        $fileContents = file_get_contents($testCaseFile);
        echo "$testCaseFile\n";

        $parserKinds = [ParserKind::DIAGNOSTIC_PHP_PARSER, ParserKind::DIAGNOSTIC_TOLERANT_PHP_PARSER];
        $parserKinds = [ParserKind::PHP_PARSER, ParserKind::TOLERANT_PHP_PARSER];

        $maxRecursion = [];

        $definitions = null;
        $instantiated = null;
        $types = null;
        $symbolInfo = null;
        $extend = null;
        $isGlobal = null;
        $documentation = null;
        $isStatic = null;

        foreach ($parserKinds as $kind) {
            echo ("=====================================\n");
            global $parserKind;
            $parserKind = $kind;

            $index = $this->getIndex($kind, $frameworkName);
            $docBlockFactory = DocBlockFactory::createInstance();

            $definitionResolver = ParserResourceFactory::getDefinitionResolver($index);
            $parser = ParserResourceFactory::getParser();

            try {
                $document = new PhpDocument($testCaseFile, $fileContents, $index, $parser, $docBlockFactory, $definitionResolver);
            } catch (Exception $e) {
                if ($kind === $parserKinds[0]) {
                    $this->markTestIncomplete("baseline parser failed: " . $e->getTraceAsString());
                }
                throw $e;

            }

            if ($document->getStmts() === null) {
                $this->markTestSkipped("null AST");
            }

            $fqns = [];
            $currentTypes = [];
            $canBeInstantiated = [];
            $symbols = [];
            $extends = [];
            $global = [];
            $docs = [];
            $static = [];
            foreach ($document->getDefinitions() as $defn) {
                $fqns[] = $defn->fqn;
                $currentTypes[$defn->fqn] = $defn->type;
                $canBeInstantiated[$defn->fqn] = $defn->canBeInstantiated;

                $defn->symbolInformation->location = null;
                $symbols[$defn->fqn] = $defn->symbolInformation;

                $extends[$defn->fqn] = $defn->extends;
                $global[$defn->fqn] = $defn->isGlobal;
                $docs[$defn->fqn] = $defn->documentation;
                $static[$defn->fqn] = $defn->isStatic;
            }
            if ($definitions !== null) {

                $this->assertEquals($definitions, $fqns, 'defn->fqn does not match');
//                $this->assertEquals($types, $currentTypes, "defn->type does not match");
                $this->assertEquals($instantiated, $canBeInstantiated, "defn->canBeInstantiated does not match");
                $this->assertEquals($extend, $extends, 'defn->extends does not match');
                $this->assertEquals($isGlobal, $global, 'defn->isGlobal does not match');
                $this->assertEquals($documentation, $docs, 'defn->documentation does not match');
                $this->assertEquals($isStatic, $static, 'defn->isStatic does not match');

                $this->assertEquals($symbolInfo, $symbols, "defn->symbolInformation does not match");


                $skipped = [];
                $skipped = [
                    'false', 'true', 'null', 'FALSE', 'TRUE', 'NULL',
                    '__', // magic constants are treated as normal constants
                    'Exception', // catch exception types missing from old definition resolver
                    'Trait' // use Trait references are missing from old definition resolve
                ];
                foreach ($this->getIndex($parserKinds[0], $frameworkName)->references as $key=>$value) {
                    foreach ($skipped as $s) {
                        if (strpos($key, $s) !== false) {
                            unset($this->getIndex($parserKinds[0], $frameworkName)->references[$key]);
                        }
                    }
                }
                foreach ($this->getIndex($parserKinds[1], $frameworkName)->references as $key=>$value) {
                    foreach ($skipped as $s) {
                        if (strpos($key, $s) !== false) {
                            unset($this->getIndex($parserKinds[1], $frameworkName)->references[$key]);
                        }
                    }
                }

//                unset($this->getIndex($parserKinds[1])->references['__LINE__']);
//                unset($this->getIndex($parserKinds[1])->references['__FILE__']);
//                unset($this->getIndex($parserKinds[1])->references['Exception']);
//                unset($this->getIndex($parserKinds[1])->references['__METHOD__']);
//                unset($this->getIndex($parserKinds[1])->references['__FUNCTION__']);
//                unset($this->getIndex($parserKinds[1])->references['Requests_Exception']);

                try {

//                    $this->assertEquals($this->getIndex($parserKinds[0], $frameworkName)->references, $this->getIndex($parserKinds[1], $frameworkName)->references,
//                        "references do not match");

                    $this->assertArraySubset($this->getIndex($parserKinds[0], $frameworkName)->references, $this->getIndex($parserKinds[1], $frameworkName)->references);
//                    var_dump(array_keys($this->getIndex($parserKinds[1], $frameworkName)->references));
                }
                catch (\Throwable $e) {
                    $this->assertEquals($this->getIndex($parserKinds[0], $frameworkName)->references, $this->getIndex($parserKinds[1], $frameworkName)->references,
                        "references do not match");
                }
                finally {
                    unset($this->index[$parserKinds[0]][$frameworkName]);
                    unset($this->index[$parserKinds[1]][$frameworkName]);
                }
            }

            $definitions = $fqns;
            $types = $currentTypes;
            $instantiated = $canBeInstantiated;
            $symbolInfo = $symbols;
            $extend = $extends;
            $isGlobal = $global;
            $documentation = $docs;
            $isStatic = $static;

//            $maxRecursion[$testCaseFile] = $definitionResolver::$maxRecursion;
        }
    }
}