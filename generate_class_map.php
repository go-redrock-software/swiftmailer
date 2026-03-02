<?php

require __DIR__.'/vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

class ClassNameVisitor extends NodeVisitorAbstract
{
    private array $classMap = [];

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_) {
            $name = $node->name->toString();

            if (!\str_contains($name, '_')) {
                return;
            }

            $parts = \explode('_', $name);
            $newClassName = \implode('\\', $parts);
            $this->classMap[$name] = $newClassName;
        }
    }

    public function getClassMap(): array
    {
        return $this->classMap;
    }
}

function generateClassMap(string ...$directories): array
{
    $parser = (new ParserFactory())->createForVersion(\PhpParser\PhpVersion::getHostVersion());
    $traverser = new NodeTraverser();
    $visitor = new ClassNameVisitor();
    $traverser->addVisitor($visitor);

    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isDir() || 'php' !== $file->getExtension()) {
                continue;
            }

            $code = \file_get_contents($file->getPathname());

            try {
                $ast = $parser->parse($code);
                $traverser->traverse($ast);
            } catch (Error $error) {
                echo 'Parse Error: ', $error->getMessage(), \PHP_EOL;
            }
        }
    }

    return $visitor->getClassMap();
}

$classMap = generateClassMap(__DIR__.'/lib');
\ksort($classMap);
\file_put_contents(__DIR__.'/class_map.php', '<?php return '.\var_export($classMap, true).";\n", \LOCK_EX);

echo \sprintf("Generated class map with %d entries.\n", \count($classMap));
