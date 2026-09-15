<?php

declare(strict_types=1);

namespace App\Tools\Architecture;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Valid;
use Symfony\Component\Validator\Mapping\CascadingStrategy;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Mapping\PropertyMetadata;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Checks effective native Default-group mappings, including registration and cascades. */
final class CollectionValidationMetadata
{
    /** @return list<string> */
    public function violations(CollectionContracts $contracts, ValidatorInterface $validator): array
    {
        if ([] !== $contracts->violations) {
            return $contracts->violations;
        }
        $errors = [];
        $classes = array_unique([...array_keys($contracts->lists), ...array_keys($contracts->cascades)]);
        foreach ($classes as $class) {
            try {
                $metadata = $validator->getMetadataFor($class);
                if (!$metadata instanceof ClassMetadata || $metadata->hasGroupSequence() || $metadata->isGroupSequenceProvider()
                    || CascadingStrategy::NONE !== $metadata->getCascadingStrategy()) {
                    $errors[] = 'collection.metadata: '.$class.' requires ordinary native Default-group property metadata, without class cascades or group-sequence overrides';
                    continue;
                }
                $properties = array_unique([...array_keys($contracts->lists[$class] ?? []), ...($contracts->cascades[$class] ?? [])]);
                foreach ($properties as $property) {
                    $constraints = [];
                    $cascade = false;
                    foreach ($metadata->getPropertyMetadata($property) as $member) {
                        if (!$member instanceof PropertyMetadata) {
                            continue;
                        }
                        $constraints = [...$constraints, ...$member->findConstraints(Constraint::DEFAULT_GROUP)];
                        $cascade = $cascade || CascadingStrategy::CASCADE === $member->getCascadingStrategy();
                    }
                    foreach ($constraints as $constraint) {
                        $cascade = $cascade || Valid::class === $constraint::class;
                    }
                    $prefix = 'collection.metadata: '.$class.'::$'.$property.' ';
                    if (isset($contracts->lists[$class][$property])) {
                        $type = $contracts->lists[$class][$property]['type'];
                        if (!$this->hasType($constraints, 'list')) {
                            $errors[] = $prefix.'requires native Type(list) in Default';
                        }
                        $bounded = $notNull = $typed = false;
                        foreach ($constraints as $constraint) {
                            if (Count::class === $constraint::class
                                && is_int($constraint->max) && $constraint->max >= 0) {
                                $bounded = true;
                            }
                            if (All::class !== $constraint::class) {
                                continue;
                            }
                            // All and each nested constraint must actually run in Default.
                            $items = array_values(array_filter($constraint->getNestedConstraints(), static fn (Constraint $item): bool => in_array(Constraint::DEFAULT_GROUP, $item->groups ?? [], true)));
                            $typed = $typed || $this->hasType($items, $type);
                            foreach ($items as $item) {
                                $notNull = $notNull || NotNull::class === $item::class;
                            }
                        }
                        if (!$bounded) {
                            $errors[] = $prefix.'requires native Count with a finite nonnegative integer max in Default';
                        }
                        if (!$notNull || !$typed) {
                            $errors[] = $prefix.'requires native All(NotNull + Type('.$type.')) in Default';
                        }
                    }
                    if (in_array($property, $contracts->cascades[$class] ?? [], true) && !$cascade) {
                        $errors[] = $prefix.'requires property Valid cascading in Default';
                    }
                }
            } catch (\Throwable) {
                // Mapping failures may include arbitrary configured values. Diagnostics
                // identify the declaration without dumping constraints or exceptions.
                $errors[] = 'collection.metadata: '.$class.' native validator metadata could not be loaded';
            }
        }
        sort($errors);

        return array_values(array_unique($errors));
    }

    /** @param array<Constraint> $constraints */
    private function hasType(array $constraints, string $type): bool
    {
        foreach ($constraints as $constraint) {
            if (Type::class === $constraint::class && $type === $constraint->type) {
                return true;
            }
        }

        return false;
    }
}
