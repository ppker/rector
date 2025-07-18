<?php

declare (strict_types=1);
namespace Rector\NodeTypeResolver;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType as NodeUnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Broker\ClassNotFoundException;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\Native\NativeFunctionReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeWithClassName;
use PHPStan\Type\UnionType;
use Rector\Configuration\RenamedClassesDataCollector;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeAnalyzer\ClassAnalyzer;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\Contract\NodeTypeResolverAwareInterface;
use Rector\NodeTypeResolver\Contract\NodeTypeResolverInterface;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\NodeTypeCorrector\AccessoryNonEmptyStringTypeCorrector;
use Rector\NodeTypeResolver\NodeTypeCorrector\GenericClassStringTypeCorrector;
use Rector\NodeTypeResolver\PHPStan\ObjectWithoutClassTypeWithParentTypes;
use Rector\StaticTypeMapper\ValueObject\Type\AliasedObjectType;
use Rector\StaticTypeMapper\ValueObject\Type\ShortenedObjectType;
use Rector\TypeDeclaration\PHPStan\ObjectTypeSpecifier;
final class NodeTypeResolver
{
    /**
     * @readonly
     */
    private ObjectTypeSpecifier $objectTypeSpecifier;
    /**
     * @readonly
     */
    private ClassAnalyzer $classAnalyzer;
    /**
     * @readonly
     */
    private GenericClassStringTypeCorrector $genericClassStringTypeCorrector;
    /**
     * @readonly
     */
    private ReflectionProvider $reflectionProvider;
    /**
     * @readonly
     */
    private AccessoryNonEmptyStringTypeCorrector $accessoryNonEmptyStringTypeCorrector;
    /**
     * @readonly
     */
    private RenamedClassesDataCollector $renamedClassesDataCollector;
    /**
     * @readonly
     */
    private NodeNameResolver $nodeNameResolver;
    /**
     * @var string
     */
    private const ERROR_MESSAGE = '%s itself does not have any type. Check the %s node instead';
    /**
     * @var array<class-string<Node>, NodeTypeResolverInterface>
     */
    private array $nodeTypeResolvers = [];
    /**
     * @param NodeTypeResolverInterface[] $nodeTypeResolvers
     */
    public function __construct(ObjectTypeSpecifier $objectTypeSpecifier, ClassAnalyzer $classAnalyzer, GenericClassStringTypeCorrector $genericClassStringTypeCorrector, ReflectionProvider $reflectionProvider, AccessoryNonEmptyStringTypeCorrector $accessoryNonEmptyStringTypeCorrector, RenamedClassesDataCollector $renamedClassesDataCollector, NodeNameResolver $nodeNameResolver, iterable $nodeTypeResolvers)
    {
        $this->objectTypeSpecifier = $objectTypeSpecifier;
        $this->classAnalyzer = $classAnalyzer;
        $this->genericClassStringTypeCorrector = $genericClassStringTypeCorrector;
        $this->reflectionProvider = $reflectionProvider;
        $this->accessoryNonEmptyStringTypeCorrector = $accessoryNonEmptyStringTypeCorrector;
        $this->renamedClassesDataCollector = $renamedClassesDataCollector;
        $this->nodeNameResolver = $nodeNameResolver;
        foreach ($nodeTypeResolvers as $nodeTypeResolver) {
            if ($nodeTypeResolver instanceof NodeTypeResolverAwareInterface) {
                $nodeTypeResolver->autowire($this);
            }
            foreach ($nodeTypeResolver->getNodeClasses() as $nodeClass) {
                $this->nodeTypeResolvers[$nodeClass] = $nodeTypeResolver;
            }
        }
    }
    /**
     * @api doctrine symfony
     * @param ObjectType[] $requiredTypes
     */
    public function isObjectTypes(Node $node, array $requiredTypes) : bool
    {
        foreach ($requiredTypes as $requiredType) {
            if ($this->isObjectType($node, $requiredType)) {
                return \true;
            }
        }
        return \false;
    }
    public function isObjectType(Node $node, ObjectType $requiredObjectType) : bool
    {
        if ($node instanceof ClassConstFetch) {
            return \false;
        }
        // warn about invalid use of this method
        if ($node instanceof ClassMethod || $node instanceof ClassConst) {
            throw new ShouldNotHappenException(\sprintf(self::ERROR_MESSAGE, \get_class($node), ClassLike::class));
        }
        $resolvedType = $this->getType($node);
        // cover call $this on trait
        if ($resolvedType instanceof ErrorType && ($node instanceof Variable && $this->nodeNameResolver->isName($node, 'this'))) {
            $scope = $node->getAttribute(AttributeKey::SCOPE);
            if (!$scope instanceof Scope) {
                return \false;
            }
            $classReflection = $scope->getClassReflection();
            if (!$classReflection instanceof ClassReflection) {
                return \false;
            }
            if ($classReflection->isTrait()) {
                $resolvedType = new ObjectType($classReflection->getName());
            }
        }
        if ($resolvedType instanceof MixedType) {
            return \false;
        }
        if ($resolvedType instanceof ThisType) {
            $resolvedType = $resolvedType->getStaticObjectType();
        }
        if ($resolvedType instanceof ObjectType) {
            try {
                return $this->resolveObjectType($resolvedType, $requiredObjectType);
            } catch (ClassNotFoundException $exception) {
                // in some type checks, the provided type in rector.php configuration does not have to exists
                return \false;
            }
        }
        if ($resolvedType instanceof ObjectWithoutClassType) {
            return $this->isMatchObjectWithoutClassType($resolvedType, $requiredObjectType);
        }
        return $this->isMatchingUnionType($resolvedType, $requiredObjectType);
    }
    public function getType(Node $node) : Type
    {
        if ($node instanceof NullableType) {
            $type = $this->getType($node->type);
            if (!$type instanceof MixedType) {
                return new UnionType([$type, new NullType()]);
            }
        }
        if ($node instanceof Ternary) {
            $ternaryType = $this->resolveTernaryType($node);
            if (!$ternaryType instanceof MixedType) {
                return $ternaryType;
            }
        }
        if ($node instanceof Coalesce) {
            $first = $this->getType($node->left);
            $second = $this->getType($node->right);
            if ($this->isUnionTypeable($first, $second)) {
                return new UnionType([$first, $second]);
            }
        }
        $type = $this->resolveByNodeTypeResolvers($node);
        if ($type instanceof Type) {
            $type = $this->accessoryNonEmptyStringTypeCorrector->correct($type);
            $type = $this->genericClassStringTypeCorrector->correct($type);
            if ($type instanceof ObjectType) {
                $scope = $node->getAttribute(AttributeKey::SCOPE);
                $type = $this->objectTypeSpecifier->narrowToFullyQualifiedOrAliasedObjectType($node, $type, $scope, \true);
            }
            return $type;
        }
        $scope = $node->getAttribute(AttributeKey::SCOPE);
        if (!$scope instanceof Scope) {
            return new MixedType();
        }
        if ($node instanceof NodeUnionType) {
            $types = [];
            foreach ($node->types as $type) {
                $types[] = $this->getType($type);
            }
            return new UnionType($types);
        }
        if (!$node instanceof Expr) {
            return new MixedType();
        }
        $type = $scope->getType($node);
        $type = $this->accessoryNonEmptyStringTypeCorrector->correct($type);
        $type = $this->genericClassStringTypeCorrector->correct($type);
        // hot fix for phpstan not resolving chain method calls
        if (!$node instanceof MethodCall) {
            return $type;
        }
        if (!$type instanceof MixedType) {
            return $type;
        }
        return $this->getType($node->var);
    }
    /**
     * e.g. string|null, ObjectNull|null
     */
    public function isNullableType(Node $node) : bool
    {
        $nodeType = $this->getType($node);
        return TypeCombinator::containsNull($nodeType);
    }
    public function getNativeType(Expr $expr) : Type
    {
        $scope = $expr->getAttribute(AttributeKey::SCOPE);
        if (!$scope instanceof Scope) {
            return new MixedType();
        }
        // cover direct New_ class
        if ($this->classAnalyzer->isAnonymousClass($expr)) {
            $type = $this->nodeTypeResolvers[New_::class]->resolve($expr);
            if ($type instanceof ObjectWithoutClassType) {
                return $type;
            }
        }
        $type = $this->resolveNativeTypeWithBuiltinMethodCallFallback($expr, $scope);
        if ($expr instanceof ArrayDimFetch) {
            $type = $this->resolveArrayDimFetchType($expr, $scope, $type);
        }
        if (!$type instanceof UnionType) {
            if ($this->isAnonymousObjectType($type)) {
                return new ObjectWithoutClassType();
            }
            return $this->accessoryNonEmptyStringTypeCorrector->correct($type);
        }
        return $this->resolveNativeUnionType($type);
    }
    public function isNumberType(Expr $expr) : bool
    {
        $nodeType = $this->getNativeType($expr);
        if ($nodeType->isInteger()->yes()) {
            return \true;
        }
        return $nodeType->isFloat()->yes();
    }
    /**
     * @template TType as Type
     *
     * @param class-string<TType> $desiredType
     * @return TType|null
     */
    public function matchNullableTypeOfSpecificType(Expr $expr, string $desiredType) : ?Type
    {
        $nodeType = $this->getType($expr);
        if (!$nodeType instanceof UnionType) {
            return null;
        }
        $bareType = TypeCombinator::removeNull($nodeType);
        if (!$bareType instanceof $desiredType) {
            return null;
        }
        return $bareType;
    }
    public function getFullyQualifiedClassName(TypeWithClassName $typeWithClassName) : string
    {
        if ($typeWithClassName instanceof ShortenedObjectType) {
            return $typeWithClassName->getFullyQualifiedName();
        }
        if ($typeWithClassName instanceof AliasedObjectType) {
            return $typeWithClassName->getFullyQualifiedName();
        }
        return $typeWithClassName->getClassName();
    }
    public function isMethodStaticCallOrClassMethodObjectType(Node $node, ObjectType $objectType) : bool
    {
        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            if ($this->isEnumTypeMatch($node, $objectType)) {
                return \true;
            }
            // method call is variable return
            return $this->isObjectType($node->var, $objectType);
        }
        if ($node instanceof StaticCall) {
            return $this->isObjectType($node->class, $objectType);
        }
        $scope = $node->getAttribute(AttributeKey::SCOPE);
        if (!$scope instanceof Scope) {
            return \false;
        }
        $classReflection = $scope->getClassReflection();
        if (!$classReflection instanceof ClassReflection) {
            return \false;
        }
        if ($classReflection->getName() === $objectType->getClassName()) {
            return \true;
        }
        if ($classReflection->is($objectType->getClassName())) {
            return \true;
        }
        return $classReflection->hasTraitUse($objectType->getClassName());
    }
    /**
     * Allow pull type from
     *
     *      - native function
     *      - always defined by assignment
     *
     * eg:
     *
     *  $parts = parse_url($url);
     *  if (!empty($parts['host'])) { }
     *
     * or
     *
     *  $parts = ['host' => 'foo'];
     *  if (!empty($parts['host'])) { }
     */
    private function resolveArrayDimFetchType(ArrayDimFetch $arrayDimFetch, Scope $scope, Type $originalNativeType) : Type
    {
        $nativeVariableType = $scope->getNativeType($arrayDimFetch->var);
        if ($nativeVariableType instanceof MixedType || $nativeVariableType instanceof ArrayType && $nativeVariableType->getIterableValueType() instanceof MixedType) {
            return $originalNativeType;
        }
        $type = $scope->getType($arrayDimFetch);
        if (!$arrayDimFetch->dim instanceof String_) {
            return $type;
        }
        $variableType = $scope->getType($arrayDimFetch->var);
        if (!$variableType instanceof ConstantArrayType) {
            return $type;
        }
        $optionalKeys = $variableType->getOptionalKeys();
        foreach ($variableType->getKeyTypes() as $key => $keyType) {
            if (!$keyType instanceof ConstantStringType) {
                continue;
            }
            if ($keyType->getValue() !== $arrayDimFetch->dim->value) {
                continue;
            }
            if (!\in_array($key, $optionalKeys, \true)) {
                continue;
            }
            return $originalNativeType;
        }
        return $type;
    }
    private function resolveNativeUnionType(UnionType $unionType) : Type
    {
        $hasChanged = \false;
        $types = $unionType->getTypes();
        foreach ($types as $key => $childType) {
            if ($this->isAnonymousObjectType($childType)) {
                $types[$key] = new ObjectWithoutClassType();
                $hasChanged = \true;
            }
        }
        if ($hasChanged) {
            return new UnionType($types);
        }
        return $unionType;
    }
    private function isMatchObjectWithoutClassType(ObjectWithoutClassType $objectWithoutClassType, ObjectType $requiredObjectType) : bool
    {
        if ($objectWithoutClassType instanceof ObjectWithoutClassTypeWithParentTypes) {
            foreach ($objectWithoutClassType->getParentTypes() as $typeWithClassName) {
                if ($requiredObjectType->isSuperTypeOf($typeWithClassName)->yes()) {
                    return \true;
                }
            }
        }
        return \false;
    }
    private function isAnonymousObjectType(Type $type) : bool
    {
        if (!$type instanceof ObjectType) {
            return \false;
        }
        $classReflection = $type->getClassReflection();
        if (!$classReflection instanceof ClassReflection) {
            return \false;
        }
        return $classReflection->isAnonymous();
    }
    private function isUnionTypeable(Type $first, Type $second) : bool
    {
        return !$first instanceof UnionType && !$second instanceof UnionType && !$second->isNull()->yes();
    }
    private function isMatchingUnionType(Type $resolvedType, ObjectType $requiredObjectType) : bool
    {
        $type = TypeCombinator::removeNull($resolvedType);
        if ($type instanceof NeverType) {
            return \false;
        }
        // for falsy nullables
        $type = TypeCombinator::remove($type, new ConstantBooleanType(\false));
        if ($type instanceof ObjectWithoutClassType) {
            return $this->isMatchObjectWithoutClassType($type, $requiredObjectType);
        }
        return $requiredObjectType->isSuperTypeOf($type)->yes();
    }
    private function resolveByNodeTypeResolvers(Node $node) : ?Type
    {
        foreach ($this->nodeTypeResolvers as $nodeClass => $nodeTypeResolver) {
            if (!$node instanceof $nodeClass) {
                continue;
            }
            return $nodeTypeResolver->resolve($node);
        }
        return null;
    }
    private function isObjectTypeOfObjectType(ObjectType $resolvedObjectType, ObjectType $requiredObjectType) : bool
    {
        $requiredClassName = $requiredObjectType->getClassName();
        $resolvedClassName = $resolvedObjectType->getClassName();
        if ($resolvedClassName === $requiredClassName) {
            return \true;
        }
        if ($resolvedObjectType->isInstanceOf($requiredClassName)->yes()) {
            return \true;
        }
        if (!$this->reflectionProvider->hasClass($requiredClassName)) {
            return \false;
        }
        $requiredClassReflection = $this->reflectionProvider->getClass($requiredClassName);
        if ($requiredClassReflection->isTrait()) {
            if (!$this->reflectionProvider->hasClass($resolvedClassName)) {
                return \false;
            }
            $resolvedClassReflection = $this->reflectionProvider->getClass($resolvedClassName);
            foreach ($resolvedClassReflection->getAncestors() as $ancestorClassReflection) {
                if ($ancestorClassReflection->hasTraitUse($requiredClassName)) {
                    return \true;
                }
            }
        }
        return \false;
    }
    private function resolveObjectType(ObjectType $resolvedObjectType, ObjectType $requiredObjectType) : bool
    {
        $renamedObjectType = $this->renamedClassesDataCollector->matchClassName($resolvedObjectType);
        if (!$renamedObjectType instanceof ObjectType) {
            return $this->isObjectTypeOfObjectType($resolvedObjectType, $requiredObjectType);
        }
        if (!$this->isObjectTypeOfObjectType($renamedObjectType, $requiredObjectType)) {
            return $this->isObjectTypeOfObjectType($resolvedObjectType, $requiredObjectType);
        }
        return \true;
    }
    /**
     * @return \PHPStan\Type\MixedType|\PHPStan\Type\UnionType
     */
    private function resolveTernaryType(Ternary $ternary)
    {
        if ($ternary->if instanceof Expr) {
            $first = $this->getType($ternary->if);
            $second = $this->getType($ternary->else);
            if ($this->isUnionTypeable($first, $second)) {
                return new UnionType([$first, $second]);
            }
        }
        $condType = $this->getType($ternary->cond);
        if ($this->isNullableType($ternary->cond) && $condType instanceof UnionType) {
            $first = $condType->getTypes()[0];
            $second = $this->getType($ternary->else);
            if ($this->isUnionTypeable($first, $second)) {
                return new UnionType([$first, $second]);
            }
        }
        return new MixedType();
    }
    /**
     * Method calls on native PHP classes report mixed,
     * even on strict known type; this fallbacks to getType() that provides correct type
     */
    private function resolveNativeTypeWithBuiltinMethodCallFallback(Expr $expr, Scope $scope) : Type
    {
        if ($expr instanceof MethodCall) {
            $callerType = $scope->getType($expr->var);
            if ($callerType instanceof ObjectType && $callerType->getClassReflection() instanceof ClassReflection && $callerType->getClassReflection()->isBuiltin()) {
                return $scope->getType($expr);
            }
        }
        if ($expr instanceof FuncCall) {
            if (!$expr->name instanceof Name) {
                return $scope->getNativeType($expr);
            }
            $functionName = new Name((string) $this->nodeNameResolver->getName($expr));
            if (!$this->reflectionProvider->hasFunction($functionName, null)) {
                return $scope->getNativeType($expr);
            }
            $functionReflection = $this->reflectionProvider->getFunction($functionName, null);
            if (!$functionReflection instanceof NativeFunctionReflection) {
                return $scope->getNativeType($expr);
            }
            return $scope->getType($expr);
        }
        return $scope->getNativeType($expr);
    }
    /**
     * @param \PhpParser\Node\Expr\MethodCall|\PhpParser\Node\Expr\NullsafeMethodCall $call
     */
    private function isEnumTypeMatch($call, ObjectType $objectType) : bool
    {
        if (!$call->var instanceof ClassConstFetch) {
            return \false;
        }
        // possibly enum
        $classConstFetch = $call->var;
        if (!$classConstFetch->class instanceof FullyQualified) {
            return \false;
        }
        $className = $classConstFetch->class->toString();
        if (!$this->reflectionProvider->hasClass($className)) {
            return \false;
        }
        $classReflection = $this->reflectionProvider->getClass($className);
        if (!$classReflection->isEnum()) {
            return \false;
        }
        return $classReflection->getName() === $objectType->getClassName();
    }
}
