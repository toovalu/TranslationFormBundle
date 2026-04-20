<?php

declare(strict_types=1);

/*
 * This file is part of the TranslationFormBundle package.
 *
 * (c) David ALLIX <http://a2lix.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace A2lix\TranslationFormBundle\Form\Doctrine;

use A2lix\AutoFormBundle\Form\Type\AutoType;
use A2lix\TranslationFormBundle\Form\TranslationFieldsConfigProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormInterface;

/**
 * Builds per-field options for {@see AutoType} from Doctrine ORM metadata (same role as
 * AutoFormBundle 0.x DoctrineORMManipulator and DoctrineORMInfo).
 */
final class DoctrineTranslationFieldsConfigProvider implements TranslationFieldsConfigProviderInterface
{
    /**
     * @param list<string> $globalExcludedFields
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $globalExcludedFields = ['id', 'locale', 'translatable'],
    ) {}

    #[\Override]
    public function getFieldsConfig(FormInterface $form): array
    {
        $class = $this->getDataClass($form);
        $formOptions = $form->getConfig()->getOptions();

        $objectFieldsConfig = $this->getObjectFieldsConfig($class);
        $validObjectFieldsConfig = $this->filteringValidObjectFields($objectFieldsConfig, $formOptions['excluded_fields']);

        if (empty($formOptions['fields'])) {
            return $validObjectFieldsConfig;
        }

        $fields = [];

        foreach ($formOptions['fields'] as $formFieldName => $formFieldConfig) {
            $this->checkFieldIsValid($formFieldName, $formFieldConfig, $validObjectFieldsConfig, $class);

            if (null === $formFieldConfig) {
                continue;
            }

            if (false === ($formFieldConfig['display'] ?? true)) {
                continue;
            }

            $fields[$formFieldName] = $this->normalizeLegacyFieldOptions($formFieldConfig);

            if (isset($validObjectFieldsConfig[$formFieldName])) {
                $fields[$formFieldName] += $validObjectFieldsConfig[$formFieldName];
            }
        }

        return $fields + $validObjectFieldsConfig;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getObjectFieldsConfig(string $class): array
    {
        $fieldsConfig = [];

        $metadata = $this->entityManager->getClassMetadata($class);

        if (!empty($fields = $metadata->getFieldNames())) {
            $fieldsConfig = array_fill_keys($fields, []);
        }

        if (!empty($assocNames = $metadata->getAssociationNames())) {
            $fieldsConfig += $this->getAssocsConfig($metadata, $assocNames);
        }

        return $fieldsConfig;
    }

    /**
     * @param array<string, array<string, mixed>> $objectFieldsConfig
     * @param list<string>                        $formExcludedFields
     *
     * @return array<string, array<string, mixed>>
     */
    private function filteringValidObjectFields(array $objectFieldsConfig, array $formExcludedFields): array
    {
        $excludedFields = array_merge($this->globalExcludedFields, $formExcludedFields);

        $validFields = [];
        foreach ($objectFieldsConfig as $fieldName => $fieldConfig) {
            if (\in_array($fieldName, $excludedFields, true)) {
                continue;
            }

            $validFields[$fieldName] = $fieldConfig;
        }

        return $validFields;
    }

    /**
     * @param array<string, array<string, mixed>> $validObjectFieldsConfig
     */
    private function checkFieldIsValid(string $formFieldName, mixed $formFieldConfig, array $validObjectFieldsConfig, string $class): void
    {
        if (isset($validObjectFieldsConfig[$formFieldName])) {
            return;
        }

        if (false === ($formFieldConfig['mapped'] ?? true)) {
            return;
        }

        throw new \RuntimeException(\sprintf("Field '%s' doesn't exist in %s", $formFieldName, $class));
    }

    /**
     * @param array<string, mixed> $formFieldConfig
     *
     * @return array<string, mixed>
     */
    private function normalizeLegacyFieldOptions(array $formFieldConfig): array
    {
        if (isset($formFieldConfig['field_type']) && !isset($formFieldConfig['child_type'])) {
            $formFieldConfig['child_type'] = $formFieldConfig['field_type'];
            unset($formFieldConfig['field_type']);
        }

        if (isset($formFieldConfig['entry_options']) && \is_array($formFieldConfig['entry_options'])) {
            $formFieldConfig['entry_options'] = $this->normalizeLegacyFieldOptions($formFieldConfig['entry_options']);
        }

        return $formFieldConfig;
    }

    /**
     * @param list<string> $assocNames
     *
     * @return array<string, array<string, mixed>>
     */
    private function getAssocsConfig(ClassMetadata $metadata, array $assocNames): array
    {
        $assocsConfigs = [];

        foreach ($assocNames as $assocName) {
            $associationMapping = $metadata->getAssociationMapping($assocName);

            if (isset($associationMapping['inversedBy'])) {
                $assocsConfigs[$assocName] = [];

                continue;
            }

            $class = $metadata->getAssociationTargetClass($assocName);

            if ($metadata->isSingleValuedAssociation($assocName)) {
                $assocsConfigs[$assocName] = [
                    'child_type' => AutoType::class,
                    'data_class' => $class,
                    'required' => false,
                ];

                continue;
            }

            $assocsConfigs[$assocName] = [
                'child_type' => CollectionType::class,
                'entry_type' => AutoType::class,
                'entry_options' => [
                    'data_class' => $class,
                ],
                'allow_add' => true,
                'by_reference' => false,
            ];
        }

        return $assocsConfigs;
    }

    private function getDataClass(FormInterface $form): string
    {
        if (null !== $dataClass = $form->getConfig()->getDataClass()) {
            if (false === $pos = strrpos((string) $dataClass, '\\__CG__\\')) {
                return $dataClass;
            }

            return substr((string) $dataClass, $pos + 8);
        }

        while (null !== $formParent = $form->getParent()) {
            if (null === $dataClass = $formParent->getConfig()->getDataClass()) {
                $form = $formParent;

                continue;
            }

            return $this->entityManager->getClassMetadata($dataClass)->getAssociationTargetClass((string) $form->getPropertyPath());
        }

        throw new \RuntimeException('Unable to get dataClass');
    }
}
