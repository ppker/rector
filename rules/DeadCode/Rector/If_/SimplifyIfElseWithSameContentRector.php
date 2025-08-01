<?php

declare (strict_types=1);
namespace Rector\DeadCode\Rector\If_;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeVisitor;
use Rector\Exception\ShouldNotHappenException;
use Rector\PhpParser\Printer\BetterStandardPrinter;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
/**
 * @see \Rector\Tests\DeadCode\Rector\If_\SimplifyIfElseWithSameContentRector\SimplifyIfElseWithSameContentRectorTest
 */
final class SimplifyIfElseWithSameContentRector extends AbstractRector
{
    /**
     * @readonly
     */
    private BetterStandardPrinter $betterStandardPrinter;
    public function __construct(BetterStandardPrinter $betterStandardPrinter)
    {
        $this->betterStandardPrinter = $betterStandardPrinter;
    }
    public function getRuleDefinition() : RuleDefinition
    {
        return new RuleDefinition('Remove if/else if they have same content', [new CodeSample(<<<'CODE_SAMPLE'
class SomeClass
{
    public function run()
    {
        if (true) {
            return 1;
        } else {
            return 1;
        }
    }
}
CODE_SAMPLE
, <<<'CODE_SAMPLE'
class SomeClass
{
    public function run()
    {
        return 1;
    }
}
CODE_SAMPLE
)]);
    }
    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes() : array
    {
        return [If_::class];
    }
    /**
     * @param If_ $node
     * @return Stmt[]|null|int
     */
    public function refactor(Node $node)
    {
        if (!$node->else instanceof Else_) {
            return null;
        }
        if (!$this->isIfWithConstantReturns($node)) {
            return null;
        }
        if ($node->stmts === []) {
            return NodeVisitor::REMOVE_NODE;
        }
        return $node->stmts;
    }
    private function isIfWithConstantReturns(If_ $if) : bool
    {
        $possibleContents = [];
        $possibleContents[] = $this->betterStandardPrinter->print($if->stmts);
        foreach ($if->elseifs as $elseif) {
            $possibleContents[] = $this->betterStandardPrinter->print($elseif->stmts);
        }
        $else = $if->else;
        if (!$else instanceof Else_) {
            throw new ShouldNotHappenException();
        }
        $possibleContents[] = $this->betterStandardPrinter->print($else->stmts);
        $uniqueContents = \array_unique($possibleContents);
        // only one content for all
        return \count($uniqueContents) === 1;
    }
}
