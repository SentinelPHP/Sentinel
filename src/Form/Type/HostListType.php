<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\DataTransformer\StringListTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A textarea form type for entering a list of host patterns (one per line).
 *
 * Uses Bootstrap styling via the configured form theme.
 *
 * @extends AbstractType<list<string>>
 */
final class HostListType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new StringListTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'attr' => [
                'rows' => 4,
            ],
        ]);
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'host_list';
    }
}
