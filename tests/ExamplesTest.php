<?php

namespace Google\Cloud\Tools\Fixer\Tests;

use Google\Cloud\Tools\NewSurfaceFixer;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Fixer\Import\OrderedImportsFixer;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

class ExamplesTest extends TestCase
{
    private NewSurfaceFixer $fixer;

    public function setUp(): void
    {
        $this->fixer = new NewSurfaceFixer();
        $this->ordered = new OrderedImportsFixer();
    }

    /**
     * @dataProvider provideLegacySamples
     */
    public function testLegacySamples($filename)
    {
        $filepath = __DIR__ . '/../examples/' . $filename;
        $tokens = Tokens::fromCode(file_get_contents($filepath));
        $fileInfo = new SplFileInfo($filepath);
        $this->fixer->fix($fileInfo, $tokens);
        $this->ordered->fix($fileInfo, $tokens);
        $code = $tokens->generateCode();
        $this->assertStringEqualsFile(str_replace('legacy_', 'new_', $filepath), $code);
    }

    public function provideLegacySamples()
    {
        return array_map(
            fn ($file) => [basename($file)],
            array_filter(
                glob(__DIR__ . '/../examples/*'),
                fn ($file) => 0 === strpos(basename($file), 'legacy_')
            )
        );
    }
}