<?php

declare(strict_types=1);

namespace Rector\Custom\Rector;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class RenameUnderscoreToNamespaceRector extends AbstractRector
{
    private array $classMap;

    public function __construct()
    {
        $this->classMap = include \dirname(__DIR__, 2).'/class_map.php';
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Renames class names with underscores to namespaced classes', [
            new CodeSample(
                <<<'CODE_SAMPLE'
                    class Swift_Foo_Bar
                    {
                    }
                    CODE_SAMPLE,
                <<<'CODE_SAMPLE'
                    namespace Swift\Foo;

                    class Bar
                    {
                    }
                    CODE_SAMPLE,
            ),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Class_) {
            return null;
        }

        $className = (string) $node->name;
        if (!isset($this->classMap[$className])) {
            return null;
        }

        $parts     = \explode('\\', $this->classMap[$className]);
        $shortName = \array_pop($parts);
        $namespace = \implode('\\', $parts);

        $node->name = new Identifier($shortName);

        $namespaceNode          = new Namespace_(new Name($namespace));
        $namespaceNode->stmts[] = $node;

        return $namespaceNode;
    }
}
