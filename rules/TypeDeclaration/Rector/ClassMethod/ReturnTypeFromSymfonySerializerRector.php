<?php

declare (strict_types=1);
namespace Rector\TypeDeclaration\Rector\ClassMethod;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Type\ObjectType;
use Rector\NodeAnalyzer\ArgsAnalyzer;
use Rector\PhpParser\Node\Value\ValueResolver;
use Rector\PHPStan\ScopeFetcher;
use Rector\Rector\AbstractRector;
use Rector\ValueObject\PhpVersionFeature;
use Rector\VendorLocker\NodeVendorLocker\ClassMethodReturnTypeOverrideGuard;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
/**
 * @see \Rector\Tests\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromSymfonySerializerRector\ReturnTypeFromSymfonySerializerRectorTest
 */
final class ReturnTypeFromSymfonySerializerRector extends AbstractRector implements MinPhpVersionInterface
{
    /**
     * @readonly
     */
    private ClassMethodReturnTypeOverrideGuard $classMethodReturnTypeOverrideGuard;
    /**
     * @readonly
     */
    private ValueResolver $valueResolver;
    /**
     * @readonly
     */
    private ArgsAnalyzer $argsAnalyzer;
    public function __construct(ClassMethodReturnTypeOverrideGuard $classMethodReturnTypeOverrideGuard, ValueResolver $valueResolver, ArgsAnalyzer $argsAnalyzer)
    {
        $this->classMethodReturnTypeOverrideGuard = $classMethodReturnTypeOverrideGuard;
        $this->valueResolver = $valueResolver;
        $this->argsAnalyzer = $argsAnalyzer;
    }
    public function getRuleDefinition() : RuleDefinition
    {
        return new RuleDefinition('Add return type from symfony serializer', [new CodeSample(<<<'CODE_SAMPLE'
final class SomeClass
{
    private \Symfony\Component\Serializer\Serializer $serializer;

    public function resolveEntity($data)
    {
        return $this->serializer->deserialize($data, SomeType::class, 'json');
    }
}
CODE_SAMPLE
, <<<'CODE_SAMPLE'
final class SomeClass
{
    private \Symfony\Component\Serializer\Serializer $serializer;

    public function resolveEntity($data): SomeType
    {
        return $this->serializer->deserialize($data, SomeType::class, 'json');
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
        return [ClassMethod::class];
    }
    public function provideMinPhpVersion() : int
    {
        return PhpVersionFeature::HAS_RETURN_TYPE;
    }
    /**
     * @param ClassMethod $node
     */
    public function refactor(Node $node) : ?Node
    {
        if ($node->stmts === null) {
            return null;
        }
        if ($node->returnType instanceof Node) {
            return null;
        }
        $scope = ScopeFetcher::fetch($node);
        if ($this->classMethodReturnTypeOverrideGuard->shouldSkipClassMethod($node, $scope)) {
            return null;
        }
        if (\count($node->stmts) !== 1) {
            return null;
        }
        if (!$node->stmts[0] instanceof Return_ || !$node->stmts[0]->expr instanceof MethodCall) {
            return null;
        }
        /** @var MethodCall $returnExpr */
        $returnExpr = $node->stmts[0]->expr;
        if (!$this->isName($returnExpr->name, 'deserialize')) {
            return null;
        }
        if ($returnExpr->isFirstClassCallable()) {
            return null;
        }
        if (!$this->isObjectType($returnExpr->var, new ObjectType('Symfony\\Component\\Serializer\\Serializer'))) {
            return null;
        }
        $args = $returnExpr->getArgs();
        if ($this->argsAnalyzer->hasNamedArg($args)) {
            return null;
        }
        if (\count($args) !== 3) {
            return null;
        }
        $type = $this->valueResolver->getValue($args[1]->value);
        if (!\is_string($type)) {
            return null;
        }
        $node->returnType = new FullyQualified($type);
        return $node;
    }
}
