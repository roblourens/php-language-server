<?php
declare(strict_types = 1);

namespace LanguageServer\Tests\Server\TextDocument;

use PHPUnit\Framework\TestCase;
use LanguageServer\Tests\MockProtocolStream;
use LanguageServer\{
    ParserResourceFactory, Server, Client, LanguageClient, PhpDocumentLoader, DefinitionResolver
};
use LanguageServer\ContentRetriever\FileSystemContentRetriever;
use LanguageServer\Index\{Index, ProjectIndex, DependenciesIndex};
use LanguageServer\Protocol\{
    TextDocumentIdentifier,
    TextDocumentItem,
    VersionedTextDocumentIdentifier,
    TextDocumentContentChangeEvent,
    Range,
    Position,
    ClientCapabilities
};

class DidChangeTest extends TestCase
{
    public function test()
    {
        $projectIndex = new ProjectIndex(new Index, new DependenciesIndex);
        $client = new LanguageClient(new MockProtocolStream, new MockProtocolStream);
        $definitionResolver = ParserResourceFactory::getDefinitionResolver($projectIndex);
        $loader = new PhpDocumentLoader(new FileSystemContentRetriever, $projectIndex, $definitionResolver);
        $textDocument = new Server\TextDocument($loader, $definitionResolver, $client, $projectIndex);
        $phpDocument = $loader->open('whatever', "<?php\necho 'Hello, World'\n");

        $identifier = new VersionedTextDocumentIdentifier('whatever');
        $changeEvent = new TextDocumentContentChangeEvent();
        $changeEvent->range = new Range(new Position(0, 0), new Position(9999, 9999));
        $changeEvent->rangeLength = 9999;
        $changeEvent->text = "<?php\necho 'Goodbye, World'\n";

        $textDocument->didChange($identifier, [$changeEvent]);

        $this->assertEquals("<?php\necho 'Goodbye, World'\n", $phpDocument->getContent());
    }
}
