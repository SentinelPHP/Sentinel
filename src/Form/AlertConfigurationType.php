<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AlertConfiguration;
use App\Entity\ApiToken;
use App\Enum\AlertChannelType;
use SentinelPHP\Drift\Enum\DriftSeverity;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<AlertConfiguration>
 */
final class AlertConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('channelType', EnumType::class, [
                'class' => AlertChannelType::class,
                'label' => 'Channel Type',
                'choice_label' => fn (AlertChannelType $type): string => match ($type) {
                    AlertChannelType::Slack => 'Slack',
                    AlertChannelType::Webhook => 'Webhook',
                    AlertChannelType::Email => 'Email',
                },
                'placeholder' => 'Select a channel type...',
                'attr' => [
                    'data-controller' => 'alert-config-form',
                    'data-action' => 'change->alert-config-form#onChannelTypeChange',
                ],
            ])
            ->add('minSeverity', EnumType::class, [
                'class' => DriftSeverity::class,
                'label' => 'Minimum Severity',
                'choice_label' => fn (DriftSeverity $severity): string => match ($severity) {
                    DriftSeverity::Info => 'Info (all drifts)',
                    DriftSeverity::Warning => 'Warning (warning and critical)',
                    DriftSeverity::Critical => 'Critical (critical only)',
                },
                'help' => 'Only send alerts for drifts at or above this severity level.',
            ])
            ->add('token', EntityType::class, [
                'class' => ApiToken::class,
                'label' => 'Token Scope',
                'required' => false,
                'placeholder' => 'Global (all tokens)',
                'choice_label' => 'name',
                'query_builder' => fn (EntityRepository $er): QueryBuilder => $er->createQueryBuilder('t')
                    ->where('t.isActive = :active')
                    ->setParameter('active', true)
                    ->orderBy('t.name', 'ASC'),
                'help' => 'Leave empty to apply this alert configuration to all tokens.',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Active',
                'required' => false,
                'help' => 'Inactive configurations will not send alerts.',
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, $this->onPreSetData(...));
        $builder->addEventListener(FormEvents::PRE_SUBMIT, $this->onPreSubmit(...));
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->onPostSubmit(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AlertConfiguration::class,
        ]);
    }

    private function onPreSetData(FormEvent $event): void
    {
        $alert = $event->getData();
        $form = $event->getForm();

        if (!$alert instanceof AlertConfiguration) {
            $this->addChannelFields($form, null, []);
            return;
        }

        $reflection = new \ReflectionClass($alert);
        $channelTypeProp = $reflection->getProperty('channelType');
        $channelType = $channelTypeProp->isInitialized($alert) ? $alert->getChannelType() : null;

        $this->addChannelFields($form, $channelType, $alert->getChannelConfig());
    }

    private function onPreSubmit(FormEvent $event): void
    {
        /** @var array<string, mixed> $data */
        $data = $event->getData();
        $form = $event->getForm();

        $channelTypeValue = $data['channelType'] ?? null;
        $channelType = is_string($channelTypeValue) ? AlertChannelType::tryFrom($channelTypeValue) : null;

        $this->addChannelFields($form, $channelType, []);
    }

    private function onPostSubmit(FormEvent $event): void
    {
        $alert = $event->getData();
        $form = $event->getForm();

        if (!$alert instanceof AlertConfiguration || !$form->isValid()) {
            return;
        }

        $channelType = $alert->getChannelType();
        $config = [];

        switch ($channelType) {
            case AlertChannelType::Slack:
                $webhookUrl = $form->get('slackWebhookUrl')->getData();
                $channel = $form->get('slackChannel')->getData();

                if ($webhookUrl !== null && $webhookUrl !== '') {
                    $config['webhook_url'] = $webhookUrl;
                }
                if ($channel !== null && $channel !== '') {
                    $config['channel'] = $channel;
                }
                break;

            case AlertChannelType::Webhook:
                $url = $form->get('webhookUrl')->getData();
                $secret = $form->get('webhookSecret')->getData();

                if ($url !== null && $url !== '') {
                    $config['url'] = $url;
                }
                if ($secret !== null && $secret !== '') {
                    $config['secret'] = $secret;
                }
                break;

            case AlertChannelType::Email:
                $recipients = $form->get('emailRecipients')->getData();
                $subjectPrefix = $form->get('emailSubjectPrefix')->getData();

                if (is_string($recipients) && $recipients !== '') {
                    $config['recipients'] = array_map(
                        'trim',
                        explode(',', $recipients)
                    );
                }
                if ($subjectPrefix !== null && $subjectPrefix !== '') {
                    $config['subject_prefix'] = $subjectPrefix;
                }
                break;
        }

        $alert->setChannelConfig($config);
    }

    /**
     * @param FormInterface<AlertConfiguration> $form
     * @param array<string, mixed> $config
     */
    private function addChannelFields(FormInterface $form, ?AlertChannelType $channelType, array $config): void
    {
        $form->add('slackWebhookUrl', UrlType::class, [
            'label' => 'Slack Webhook URL',
            'required' => false,
            'mapped' => false,
            'data' => $config['webhook_url'] ?? null,
            'attr' => [
                'placeholder' => 'https://hooks.slack.com/services/...',
                'data-alert-config-form-target' => 'slackField',
            ],
            'row_attr' => [
                'class' => $channelType === AlertChannelType::Slack ? '' : 'd-none',
                'data-alert-config-form-target' => 'slackFields',
            ],
            'constraints' => $channelType === AlertChannelType::Slack ? [
                new Assert\NotBlank(message: 'Slack webhook URL is required.'),
                new Assert\Url(message: 'Please enter a valid URL.'),
            ] : [],
        ]);

        $form->add('slackChannel', TextType::class, [
            'label' => 'Slack Channel (optional)',
            'required' => false,
            'mapped' => false,
            'data' => $config['channel'] ?? null,
            'attr' => [
                'placeholder' => '#alerts',
                'data-alert-config-form-target' => 'slackField',
            ],
            'row_attr' => [
                'class' => $channelType === AlertChannelType::Slack ? '' : 'd-none',
                'data-alert-config-form-target' => 'slackFields',
            ],
            'help' => 'Override the default channel configured in the webhook.',
        ]);

        $form->add('webhookUrl', UrlType::class, [
            'label' => 'Webhook URL',
            'required' => false,
            'mapped' => false,
            'data' => $config['url'] ?? null,
            'attr' => [
                'placeholder' => 'https://your-server.com/webhook',
                'data-alert-config-form-target' => 'webhookField',
            ],
            'row_attr' => [
                'class' => $channelType === AlertChannelType::Webhook ? '' : 'd-none',
                'data-alert-config-form-target' => 'webhookFields',
            ],
            'constraints' => $channelType === AlertChannelType::Webhook ? [
                new Assert\NotBlank(message: 'Webhook URL is required.'),
                new Assert\Url(message: 'Please enter a valid URL.'),
            ] : [],
        ]);

        $form->add('webhookSecret', TextType::class, [
            'label' => 'Webhook Secret (optional)',
            'required' => false,
            'mapped' => false,
            'data' => $config['secret'] ?? null,
            'attr' => [
                'placeholder' => 'Shared secret for signature verification',
                'data-alert-config-form-target' => 'webhookField',
            ],
            'row_attr' => [
                'class' => $channelType === AlertChannelType::Webhook ? '' : 'd-none',
                'data-alert-config-form-target' => 'webhookFields',
            ],
            'help' => 'Used to sign webhook payloads for verification.',
        ]);

        $form->add('emailRecipients', TextType::class, [
            'label' => 'Email Recipients',
            'required' => false,
            'mapped' => false,
            'data' => isset($config['recipients']) && is_array($config['recipients']) ? implode(', ', array_filter($config['recipients'], 'is_string')) : null,
            'attr' => [
                'placeholder' => 'alerts@example.com, team@example.com',
                'data-alert-config-form-target' => 'emailField',
            ],
            'row_attr' => [
                'class' => $channelType === AlertChannelType::Email ? '' : 'd-none',
                'data-alert-config-form-target' => 'emailFields',
            ],
            'help' => 'Comma-separated list of email addresses.',
            'constraints' => $channelType === AlertChannelType::Email ? [
                new Assert\NotBlank(message: 'At least one email recipient is required.'),
            ] : [],
        ]);

        $form->add('emailSubjectPrefix', TextType::class, [
            'label' => 'Subject Prefix (optional)',
            'required' => false,
            'mapped' => false,
            'data' => $config['subject_prefix'] ?? null,
            'attr' => [
                'placeholder' => '[Sentinel Alert]',
                'data-alert-config-form-target' => 'emailField',
            ],
            'row_attr' => [
                'class' => $channelType === AlertChannelType::Email ? '' : 'd-none',
                'data-alert-config-form-target' => 'emailFields',
            ],
        ]);
    }
}
