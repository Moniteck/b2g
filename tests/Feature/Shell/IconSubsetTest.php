<?php

namespace Tests\Feature\Shell;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Los íconos MDI se sirven como subconjunto (resources/css/mdi.css + resources/fonts/mdi/). Si
 * alguien usa un ícono nuevo sin regenerarlo, aparecería un cuadro vacío: esta prueba lo detecta.
 * Arreglo: `node scripts/mdi-subset.mjs`.
 */
class IconSubsetTest extends TestCase
{
    public function test_every_icon_used_in_the_code_is_in_the_subset(): void
    {
        if (! is_dir(base_path('node_modules/@mdi/font'))) {
            $this->markTestSkipped('Sin node_modules/@mdi/font no se puede comparar el subconjunto.');
        }

        $process = new Process(['node', 'scripts/mdi-subset.mjs', '--check'], base_path());
        $process->run();

        $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput().$process->getOutput()));
    }
}
