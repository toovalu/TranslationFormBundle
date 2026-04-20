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

namespace A2lix\TranslationFormBundle\Tests\Form;

use A2lix\AutoFormBundle\Form\Builder\AutoTypeBuilder;
use A2lix\AutoFormBundle\Form\Type\AutoType;
use A2lix\AutoFormBundle\Form\TypeGuesser\TypeInfoTypeGuesser;
use A2lix\TranslationFormBundle\Form\Doctrine\DoctrineTranslationFieldsConfigProvider;
use A2lix\TranslationFormBundle\Form\EventListener\TranslationsFormsListener;
use A2lix\TranslationFormBundle\Form\EventListener\TranslationsListener;
use A2lix\TranslationFormBundle\Form\Type\TranslationsFormsType;
use A2lix\TranslationFormBundle\Form\Type\TranslationsType;
use A2lix\TranslationFormBundle\Locale\SimpleProvider;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Form\DoctrineOrmExtension;
use Symfony\Bridge\Doctrine\PropertyInfo\DoctrineExtractor;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Validator\Type\FormTypeValidatorExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorTypeGuesser;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormTypeGuesserChain;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase as BaseTypeTestCase;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\PhpStanExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

abstract class TypeTestCase extends BaseTypeTestCase
{
    private ?EntityManagerInterface $entityManager = null;

    private ?DoctrineTranslationFieldsConfigProvider $fieldsConfigProvider = null;

    protected function setUp(): void
    {
        parent::setUp();

        $validator = $this->getMockBuilder(ValidatorInterface::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        $this->factory = Forms::createFormFactoryBuilder()
            ->addExtensions($this->getExtensions())
            ->addTypeExtension(
                new FormTypeValidatorExtension($validator)
            )
            ->addTypeGuesser(
                $this->createMock(ValidatorTypeGuesser::class)
            )
            ->getFormFactory()
        ;

        $this->dispatcher = $this->getMockBuilder(EventDispatcherInterface::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $this->builder = new FormBuilder(null, null, $this->dispatcher, $this->factory);
    }

    protected function getDoctrineTranslationFieldsConfigProvider(): DoctrineTranslationFieldsConfigProvider
    {
        if (null !== $this->fieldsConfigProvider) {
            return $this->fieldsConfigProvider;
        }

        return $this->fieldsConfigProvider = new DoctrineTranslationFieldsConfigProvider(
            $this->getEntityManager(),
            ['id', 'locale', 'translatable']
        );
    }

    protected function getConfiguredAutoFormType(): AutoType
    {
        return new AutoType(
            new AutoTypeBuilder($this->getPropertyInfoExtractor()),
            ['id', 'locale', 'translatable']
        );
    }

    protected function getConfiguredTranslationsType(array $locales, string $defaultLocale, array $requiredLocales): TranslationsType
    {
        $translationsListener = new TranslationsListener($this->getDoctrineTranslationFieldsConfigProvider());
        $localProvider = new SimpleProvider($locales, $defaultLocale, $requiredLocales);

        return new TranslationsType($translationsListener, $localProvider);
    }

    protected function getConfiguredTranslationsFormsType(array $locales, string $defaultLocale, array $requiredLocales): TranslationsFormsType
    {
        $translationsFormsListener = new TranslationsFormsListener();
        $localProvider = new SimpleProvider($locales, $defaultLocale, $requiredLocales);

        return new TranslationsFormsType($translationsFormsListener, $localProvider);
    }

    protected function getFormExtensionsWithAutoType(): array
    {
        $managerRegistryStub = self::createStub(ManagerRegistry::class);
        $managerRegistryStub
            ->method('getManager')
            ->willReturn($this->getEntityManager())
        ;
        $managerRegistryStub
            ->method('getManagers')
            ->willReturn(['default' => $this->getEntityManager()])
        ;

        return [
            new DoctrineOrmExtension($managerRegistryStub),
            new PreloadedExtension(
                [$this->getConfiguredAutoFormType()],
                [],
                new FormTypeGuesserChain([
                    new TypeInfoTypeGuesser(TypeResolver::create()),
                ]),
            ),
        ];
    }

    private function getEntityManager(): EntityManagerInterface
    {
        if (null !== $this->entityManager) {
            return $this->entityManager;
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../Fixtures/Entity'], true);
        if (\PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);

        $this->entityManager = new EntityManager($connection, $config);
        $tool = new SchemaTool($this->entityManager);
        $tool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        return $this->entityManager;
    }

    private function getPropertyInfoExtractor(): PropertyInfoExtractor
    {
        $doctrineExtractor = new DoctrineExtractor($this->getEntityManager());
        $reflectionExtractor = new ReflectionExtractor();

        return new PropertyInfoExtractor(
            listExtractors: [
                $reflectionExtractor,
                $doctrineExtractor,
            ],
            typeExtractors: [
                $doctrineExtractor,
                new PhpStanExtractor(),
                new PhpDocExtractor(),
                $reflectionExtractor,
            ],
            accessExtractors: [
                $doctrineExtractor,
                $reflectionExtractor,
            ]
        );
    }
}
