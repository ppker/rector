<?php

declare (strict_types=1);
namespace Rector\Transform\Rector\MethodCall;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;
use Rector\Transform\ValueObject\MethodCallToPropertyFetch;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use RectorPrefix202507\Webmozart\Assert\Assert;
/**
 * @see \Rector\Tests\Transform\Rector\MethodCall\MethodCallToPropertyFetchRector\MethodCallToPropertyFetchRectorTest
 */
final class MethodCallToPropertyFetchRector extends AbstractRector implements ConfigurableRectorInterface
{
    /**
     * @var MethodCallToPropertyFetch[]
     */
    private array $methodCallsToPropertyFetches = [];
    public function getRuleDefinition() : RuleDefinition
    {
        return new RuleDefinition('Turn method call `$this->getFirstname()` to property fetch `$this->firstname`', [new ConfiguredCodeSample(<<<'CODE_SAMPLE'
class SomeClass
{
    public function run()
    {
        $this->getFirstname();
    }
}
CODE_SAMPLE
, <<<'CODE_SAMPLE'
class SomeClass
{
    public function run()
    {
        $this->firstname;
    }
}
CODE_SAMPLE
, [new MethodCallToPropertyFetch('ExamplePersonClass', 'getFirstname', 'firstname')])]);
    }
    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes() : array
    {
        return [MethodCall::class];
    }
    /**
     * @param MethodCall $node
     */
    public function refactor(Node $node) : ?Node
    {
        foreach ($this->methodCallsToPropertyFetches as $methodCallToPropertyFetch) {
            if (!$this->isName($node->name, $methodCallToPropertyFetch->getOldMethod())) {
                continue;
            }
            if (!$this->isObjectType($node->var, $methodCallToPropertyFetch->getOldObjectType())) {
                continue;
            }
            return $this->nodeFactory->createPropertyFetch($node->var, $methodCallToPropertyFetch->getNewProperty());
        }
        return null;
    }
    /**
     * @param mixed[] $configuration
     */
    public function configure(array $configuration) : void
    {
        Assert::allIsAOf($configuration, MethodCallToPropertyFetch::class);
        $this->methodCallsToPropertyFetches = $configuration;
    }
}
