<?php

declare (strict_types=1);
namespace Rector\DeadCode\Rector\Foreach_;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeFinder;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\Comments\NodeDocBlock\DocBlockUpdater;
use Rector\Contract\PhpParser\Node\StmtsAwareInterface;
use Rector\DeadCode\NodeAnalyzer\ExprUsedInNodeAnalyzer;
use Rector\NodeManipulator\StmtsManipulator;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
/**
 * @see \Rector\Tests\DeadCode\Rector\Foreach_\RemoveUnusedForeachKeyRector\RemoveUnusedForeachKeyRectorTest
 */
final class RemoveUnusedForeachKeyRector extends AbstractRector
{
    /**
     * @readonly
     */
    private NodeFinder $nodeFinder;
    /**
     * @readonly
     */
    private StmtsManipulator $stmtsManipulator;
    /**
     * @readonly
     */
    private PhpDocInfoFactory $phpDocInfoFactory;
    /**
     * @readonly
     */
    private DocBlockUpdater $docBlockUpdater;
    /**
     * @readonly
     */
    private ExprUsedInNodeAnalyzer $exprUsedInNodeAnalyzer;
    public function __construct(NodeFinder $nodeFinder, StmtsManipulator $stmtsManipulator, PhpDocInfoFactory $phpDocInfoFactory, DocBlockUpdater $docBlockUpdater, ExprUsedInNodeAnalyzer $exprUsedInNodeAnalyzer)
    {
        $this->nodeFinder = $nodeFinder;
        $this->stmtsManipulator = $stmtsManipulator;
        $this->phpDocInfoFactory = $phpDocInfoFactory;
        $this->docBlockUpdater = $docBlockUpdater;
        $this->exprUsedInNodeAnalyzer = $exprUsedInNodeAnalyzer;
    }
    public function getRuleDefinition() : RuleDefinition
    {
        return new RuleDefinition('Remove unused key in foreach', [new CodeSample(<<<'CODE_SAMPLE'
$items = [];
foreach ($items as $key => $value) {
    $result = $value;
}
CODE_SAMPLE
, <<<'CODE_SAMPLE'
$items = [];
foreach ($items as $value) {
    $result = $value;
}
CODE_SAMPLE
)]);
    }
    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes() : array
    {
        return [StmtsAwareInterface::class];
    }
    /**
     * @param StmtsAwareInterface $node
     */
    public function refactor(Node $node) : ?Node
    {
        if ($node->stmts === null) {
            return null;
        }
        $hasChanged = \false;
        foreach ($node->stmts as $key => $stmt) {
            if (!$stmt instanceof Foreach_) {
                continue;
            }
            if (!$stmt->keyVar instanceof Variable) {
                continue;
            }
            $keyVar = $stmt->keyVar;
            $isNodeUsed = (bool) $this->nodeFinder->findFirst($stmt->stmts, fn(Node $node): bool => $this->exprUsedInNodeAnalyzer->isUsed($node, $keyVar));
            if ($isNodeUsed) {
                continue;
            }
            $keyVarName = (string) $this->getName($keyVar);
            if ($this->stmtsManipulator->isVariableUsedInNextStmt($node, $key + 1, $keyVarName)) {
                continue;
            }
            $stmt->keyVar = null;
            $hasChanged = \true;
            $phpDocInfo = $this->phpDocInfoFactory->createFromNode($stmt);
            if (!$phpDocInfo instanceof PhpDocInfo) {
                continue;
            }
            $varTagValues = $phpDocInfo->getPhpDocNode()->getVarTagValues();
            foreach ($varTagValues as $varTagValue) {
                $variableName = $varTagValue->variableName;
                if ($varTagValue->variableName === '$' . $keyVarName) {
                    $phpDocInfo->removeByType(VarTagValueNode::class, $variableName);
                    $this->docBlockUpdater->updateRefactoredNodeWithPhpDocInfo($stmt);
                }
            }
        }
        if ($hasChanged) {
            return $node;
        }
        return null;
    }
}
