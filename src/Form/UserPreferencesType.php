<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\UserPreferences;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<UserPreferences>
 */
final class UserPreferencesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('defaultDateRange', ChoiceType::class, [
                'label' => 'Default Date Range',
                'choices' => array_flip(UserPreferences::DATE_RANGE_OPTIONS),
                'help' => 'Default time range for dashboard views and filters.',
            ])
            ->add('refreshInterval', ChoiceType::class, [
                'label' => 'Auto-Refresh Interval',
                'choices' => array_flip(UserPreferences::REFRESH_INTERVAL_OPTIONS),
                'help' => 'How often to automatically refresh dashboard data.',
            ])
            ->add('notificationEvents', ChoiceType::class, [
                'label' => 'Notification Events',
                'choices' => array_flip(UserPreferences::NOTIFICATION_EVENT_OPTIONS),
                'multiple' => true,
                'expanded' => true,
                'help' => 'Select which events should trigger toast notifications.',
            ])
            ->add('theme', ChoiceType::class, [
                'label' => 'Theme',
                'choices' => array_flip(UserPreferences::THEME_OPTIONS),
                'help' => 'Choose your preferred color theme.',
                'attr' => [
                    'data-controller' => 'theme-select',
                    'data-action' => 'change->theme-select#onChange',
                ],
            ])
            ->add('timezone', TimezoneType::class, [
                'label' => 'Timezone',
                'help' => 'Timestamps will be displayed in this timezone.',
                'preferred_choices' => [
                    'UTC',
                    'America/New_York',
                    'America/Los_Angeles',
                    'Europe/London',
                    'Europe/Paris',
                    'Europe/Berlin',
                    'Asia/Tokyo',
                    'Asia/Shanghai',
                    'Australia/Sydney',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UserPreferences::class,
        ]);
    }
}
