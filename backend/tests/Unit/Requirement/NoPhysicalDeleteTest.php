<?php

namespace App\Tests\Unit\Requirement;

use PHPUnit\Framework\TestCase;

/**
 * Guards the "nothing is ever physically deleted" product requirement:
 * application code must mark rows as deleted, never remove them.
 */
class NoPhysicalDeleteTest extends TestCase
{
    /**
     * Files allowed to delete physically, relative to src/, each only on the
     * owner's explicit request. Keep this list as short as it can be.
     */
    private const ALLOWED = [
        // Wipes a user's data before a fresh import, on the owner's explicit request.
        'Import/UserDataCleaner.php',
    ];

    public function testApplicationCodeNeverRemovesRows(): void
    {
        $offenders = [];
        $src = \dirname(__DIR__, 3).'/src/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || \in_array(substr($file->getPathname(), \strlen($src)), self::ALLOWED, true)) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match('/->remove\(|\bDELETE\s+FROM\b|createQuery\(\s*[\'"]DELETE\b/i', $line)) {
                    $offenders[] = \sprintf('%s:%d  %s', $file->getFilename(), $number + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $offenders, 'Use markDeleted() instead of physically deleting rows.');
    }
}
