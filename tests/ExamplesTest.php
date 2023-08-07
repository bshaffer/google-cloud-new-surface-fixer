<?php

namespace Google\Cloud\Tools\Fixer\Tests;

use Google\Cloud\Tools\NewSurfaceFixer;
use PhpCsFixer\Tokenizer\Tokens;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

class ExamplesTest extends TestCase
{
    private NewSurfaceFixer $fixer;

    public function setUp(): void
    {
        $this->fixer = new NewSurfaceFixer();
    }

    /**
     * @dataProvider provideLegacySamples
     */
    public function testLegacySamples($filename)
    {
        $legacyFilepath = __DIR__ . '/../examples/' . $filename;
        $newFilepath = str_replace('legacy_', 'new_', $legacyFilepath);
        $tokens = Tokens::fromCode(file_get_contents($legacyFilepath));
        $fileInfo = new SplFileInfo($legacyFilepath);
        $this->fixer->fix($fileInfo, $tokens);
        $code = $tokens->generateCode();
        if (!file_exists($newFilepath) || file_get_contents($newFilepath) !== $code) {
            if (getenv('UPDATE_FIXTURES=1')) {
                file_put_contents($newFilepath, $code);
                $this->markTestIncomplete('Updated fixtures');
            }
            if (!file_exists($newFilepath)) {
                $this->fail('File does not exist');
            }
        }
        $this->assertStringEqualsFile($newFilepath, $code);
    }

    public static function provideLegacySamples()
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