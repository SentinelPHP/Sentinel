<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\DataTransformer\JsonArrayTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A textarea form type for JSON input with automatic transformation.
 *
 * Uses Bootstrap styling via the configured form theme.
 *
 * @extends AbstractType<array<string, mixed>|null>
 */
final class JsonTextareaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var bool $prettyPrint */
        $prettyPrint = $options['pretty_print'];

        $builder->addModelTransformer(
            new JsonArrayTransformer($prettyPrint)
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'pretty_print' => true,
            'attr' => [
                'rows' => 4,
                'class' => 'font-monospace',
            ],
        ]);

        $resolver->setAllowedTypes('pretty_print', 'bool');
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'json_textarea';
    }
}
