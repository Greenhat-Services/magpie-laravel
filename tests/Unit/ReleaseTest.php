<?php

namespace Magpie\Laravel\Tests\Unit;

use Magpie\Laravel\Release;
use PHPUnit\Framework\TestCase;

class ReleaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/magpie-release-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/.git/refs/heads', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/.git/refs/heads/main', '/.git/packed-refs', '/.git/HEAD'] as $file) {
            @unlink($this->dir.$file);
        }
        foreach (['/.git/refs/heads', '/.git/refs', '/.git', ''] as $folder) {
            @rmdir($this->dir.$folder);
        }
    }

    public function test_it_reads_the_commit_from_a_branch_ref_packed_refs_or_a_detached_head(): void
    {
        file_put_contents($this->dir.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->dir.'/.git/refs/heads/main', str_repeat('a', 40)."\n");
        $this->assertSame(str_repeat('a', 40), Release::detect($this->dir));

        unlink($this->dir.'/.git/refs/heads/main');
        file_put_contents($this->dir.'/.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n".str_repeat('b', 40)." refs/heads/main\n");
        $this->assertSame(str_repeat('b', 40), Release::detect($this->dir));

        file_put_contents($this->dir.'/.git/HEAD', str_repeat('c', 40));
        $this->assertSame(str_repeat('c', 40), Release::detect($this->dir));
    }

    public function test_it_is_null_without_a_checkout(): void
    {
        $this->assertNull(Release::detect($this->dir.'/nowhere'));
    }
}
