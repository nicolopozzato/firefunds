<?php

namespace App\Tests\Unit\Requirement;

use PHPUnit\Framework\TestCase;

/**
 * Guards the "nothing is ever physically deleted" product requirement:
 * application code must mark rows as deleted, never remove them. The only
 * exceptions are classes marked #[PhysicalDelete], each run on the owner's
 * explicit request: keep them as few as they can be.
 */
class NoPhysicalDeleteTest extends TestCase
{
    public function testApplicationCodeNeverRemovesRows(): void
    {
        $offenders = [];
        $src = \dirname(__DIR__, 3).'/src/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_contains((string) file_get_contents($file->getPathname()), "\n#[PhysicalDelete(")) {
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
