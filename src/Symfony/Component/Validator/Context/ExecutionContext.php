<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Validator\Context;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Mapping\MemberMetadata;
use Symfony\Component\Validator\Mapping\MetadataInterface;
use Symfony\Component\Validator\Mapping\PropertyMetadataInterface;
use Symfony\Component\Validator\Util\PropertyPath;
use Symfony\Component\Validator\Validator\LazyProperty;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilder;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The context used and created by {@link ExecutionContextFactory}.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @see ExecutionContextInterface
 *
 * @internal since version 2.5. Code against ExecutionContextInterface instead.
 */
class ExecutionContext implements ExecutionContextInterface
{
    private ValidatorInterface $validator;

    /**
     * The root value of the validated object graph.
     */
    private mixed $root;

    private TranslatorInterface $translator;
    private ?string $translationDomain;

    /**
     * The violations generated in the current context.
     */
    private ConstraintViolationList $violations;

    /**
     * The currently validated value.
     */
    private mixed $value = null;

    /**
     * The currently validated object.
     */
    private ?object $object = null;

    /**
     * The property path leading to the current value.
     */
    private string $propertyPath = '';

    /**
     * The current validation metadata.
     */
    private ?MetadataInterface $metadata = null;

    /**
     * The currently validated group.
     */
    private ?string $group = null;

    /**
     * The currently validated constraint.
     */
    private ?Constraint $constraint = null;

    /**
     * Stores which objects have been validated in which group.
     *
     * @var bool[][]
     */
    private array $validatedObjects = [];

    /**
     * Stores which class constraint has been validated for which object.
     *
     * @var bool[]
     */
    private array $validatedConstraints = [];

    /**
     * Stores which objects have been initialized.
     *
     * @var bool[]
     */
    private array $initializedObjects = [];

    private \SplObjectStorage $cachedObjectsRefs;

    /**
     * @internal Called by {@link ExecutionContextFactory}. Should not be used in user code.
     */
    public function __construct(ValidatorInterface $validator, mixed $root, TranslatorInterface $translator, string $translationDomain = null)
    {
        $this->validator = $validator;
        $this->root = $root;
        $this->translator = $translator;
        $this->translationDomain = $translationDomain;
        $this->violations = new ConstraintViolationList();
        $this->cachedObjectsRefs = new \SplObjectStorage();
    }

    /**
     * {@inheritdoc}
     */
    public function setNode(mixed $value, ?object $object, MetadataInterface $metadata = null, string $propertyPath)
    {
        $this->value = $value;
        $this->object = $object;
        $this->metadata = $metadata;
        $this->propertyPath = $propertyPath;
    }

    /**
     * {@inheritdoc}
     */
    public function setGroup(?string $group)
    {
        $this->group = $group;
    }

    /**
     * {@inheritdoc}
     */
    public function setConstraint(Constraint $constraint)
    {
        $this->constraint = $constraint;
    }

    /**
     * {@inheritdoc}
     */
    public function addViolation(string $message, array $parameters = [])
    {
        $this->violations->add(new ConstraintViolation(
            $this->translator->trans($message, $parameters, $this->translationDomain),
            $message,
            $parameters,
            $this->root,
            $this->propertyPath,
            $this->getValue(),
            null,
            null,
            $this->constraint
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function buildViolation(string $message, array $parameters = []): ConstraintViolationBuilderInterface
    {
        return new ConstraintViolationBuilder(
            $this->violations,
            $this->constraint,
            $message,
            $parameters,
            $this->root,
            $this->propertyPath,
            $this->getValue(),
            $this->translator,
            $this->translationDomain
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getViolations(): ConstraintViolationListInterface
    {
        return $this->violations;
    }

    /**
     * {@inheritdoc}
     */
    public function getValidator(): ValidatorInterface
    {
        return $this->validator;
    }

    /**
     * {@inheritdoc}
     */
    public function getRoot(): mixed
    {
        return $this->root;
    }

    /**
     * {@inheritdoc}
     */
    public function getValue(): mixed
    {
        if ($this->value instanceof LazyProperty) {
            return $this->value->getPropertyValue();
        }

        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function getObject(): ?object
    {
        return $this->object;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata(): ?MetadataInterface
    {
        return $this->metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function getConstraint(): ?Constraint
    {
        return $this->constraint;
    }

    /**
     * {@inheritdoc}
     */
    public function getClassName(): ?string
    {
        return $this->metadata instanceof MemberMetadata || $this->metadata instanceof ClassMetadataInterface ? $this->metadata->getClassName() : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getPropertyName(): ?string
    {
        return $this->metadata instanceof PropertyMetadataInterface ? $this->metadata->getPropertyName() : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getPropertyPath(string $subPath = ''): string
    {
        return PropertyPath::append($this->propertyPath, $subPath);
    }

    /**
     * {@inheritdoc}
     */
    public function markGroupAsValidated(string $cacheKey, string $groupHash)
    {
        if (!isset($this->validatedObjects[$cacheKey])) {
            $this->validatedObjects[$cacheKey] = [];
        }

        $this->validatedObjects[$cacheKey][$groupHash] = true;
    }

    /**
     * {@inheritdoc}
     */
    public function isGroupValidated(string $cacheKey, string $groupHash): bool
    {
        return isset($this->validatedObjects[$cacheKey][$groupHash]);
    }

    /**
     * {@inheritdoc}
     */
    public function markConstraintAsValidated(string $cacheKey, string $constraintHash)
    {
        $this->validatedConstraints[$cacheKey.':'.$constraintHash] = true;
    }

    /**
     * {@inheritdoc}
     */
    public function isConstraintValidated(string $cacheKey, string $constraintHash): bool
    {
        return isset($this->validatedConstraints[$cacheKey.':'.$constraintHash]);
    }

    /**
     * {@inheritdoc}
     */
    public function markObjectAsInitialized(string $cacheKey)
    {
        $this->initializedObjects[$cacheKey] = true;
    }

    /**
     * {@inheritdoc}
     */
    public function isObjectInitialized(string $cacheKey): bool
    {
        return isset($this->initializedObjects[$cacheKey]);
    }

    /**
     * @internal
     */
    public function generateCacheKey(object $object): string
    {
        if (!isset($this->cachedObjectsRefs[$object])) {
            $this->cachedObjectsRefs[$object] = spl_object_hash($object);
        }

        return $this->cachedObjectsRefs[$object];
    }

    public function __clone()
    {
        $this->violations = clone $this->violations;
    }
}
