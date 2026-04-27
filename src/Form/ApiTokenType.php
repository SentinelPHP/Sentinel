<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ApiToken;
use App\Enum\DataProtectionStrategy;
use SentinelPHP\Drift\Enum\DriftSeverity;
use App\Enum\LogLevel;
use App\Enum\TokenMode;
use App\Form\Type\HostListType;
use App\Form\Type\JsonTextareaType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ApiToken>
 */
final class ApiTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Token Name',
                'attr' => [
                    'placeholder' => 'e.g., Production API, Staging Environment',
                ],
            ])
            ->add('allowedTargets', HostListType::class, [
                'label' => 'Allowed Targets',
                'required' => false,
                'attr' => [
                    'placeholder' => "api.example.com\n*.stripe.com\npayments.internal",
                ],
                'help' => 'One host pattern per line. Supports wildcards (e.g., *.example.com). Leave empty to allow all hosts.',
            ])
            ->add('mode', EnumType::class, [
                'class' => TokenMode::class,
                'label' => 'Token Mode',
                'choice_label' => fn (TokenMode $mode): string => match ($mode) {
                    TokenMode::Passive => 'Passive (proxy only, no schema operations)',
                    TokenMode::Learning => 'Learning (build schemas from traffic)',
                    TokenMode::Validating => 'Validating (detect schema drifts)',
                },
                'help' => 'Passive: proxy requests without schema operations. Learning: build schemas from observed traffic. Validating: detect and report schema drifts.',
            ])
            ->add('dataProtectionStrategy', EnumType::class, [
                'class' => DataProtectionStrategy::class,
                'label' => 'Data Protection Strategy',
                'required' => false,
                'placeholder' => 'Use global default',
                'choice_label' => fn (DataProtectionStrategy $strategy): string => match ($strategy) {
                    DataProtectionStrategy::None => 'None (store data as-is)',
                    DataProtectionStrategy::Redact => 'Redact (mask sensitive fields)',
                    DataProtectionStrategy::Encrypt => 'Encrypt (encrypt stored data)',
                    DataProtectionStrategy::RedactEncrypt => 'Redact & Encrypt (both)',
                },
            ])
            ->add('logLevel', EnumType::class, [
                'class' => LogLevel::class,
                'label' => 'Log Level',
                'required' => false,
                'placeholder' => 'Use global default',
                'choice_label' => fn (LogLevel $level): string => match ($level) {
                    LogLevel::None => 'None (no logging)',
                    LogLevel::MetadataOnly => 'Metadata Only (basic request info)',
                    LogLevel::DriftOnly => 'Drift Only (log bodies on drift)',
                    LogLevel::Headers => 'Headers (include request/response headers)',
                    LogLevel::FullAudit => 'Full Audit (complete request/response)',
                },
            ])
            ->add('alertMinSeverity', EnumType::class, [
                'class' => DriftSeverity::class,
                'label' => 'Minimum Alert Severity',
                'required' => false,
                'placeholder' => 'Use global default',
                'choice_label' => fn (DriftSeverity $severity): string => match ($severity) {
                    DriftSeverity::Info => 'Info (all drifts)',
                    DriftSeverity::Warning => 'Warning (warning and critical)',
                    DriftSeverity::Critical => 'Critical (critical only)',
                },
                'help' => 'Only send alerts for drifts at or above this severity level.',
            ])
            ->add('learningThreshold', IntegerType::class, [
                'label' => 'Learning Threshold',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g., 100',
                    'min' => 1,
                ],
                'help' => 'Number of samples required before auto-promoting schema to master (learning mode only).',
            ])
            ->add('autoSwitchToValidating', CheckboxType::class, [
                'label' => 'Auto-switch to Validating',
                'required' => false,
                'help' => 'Automatically switch to validating mode when all schemas reach the learning threshold.',
            ])
            ->add('validateRequestBody', CheckboxType::class, [
                'label' => 'Validate Request Bodies',
                'required' => false,
                'help' => 'Also validate request bodies against learned schemas (not just responses).',
            ])
            ->add('customRedactionPatterns', JsonTextareaType::class, [
                'label' => 'Custom Redaction Patterns',
                'required' => false,
                'attr' => [
                    'placeholder' => '{"ssn": "\\d{3}-\\d{2}-\\d{4}", "api_key": "sk_[a-zA-Z0-9]+"}',
                    'rows' => 3,
                ],
                'help' => 'JSON object mapping field names to regex patterns for custom redaction.',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Active',
                'required' => false,
                'help' => 'Inactive tokens will reject all requests.',
            ])
            ->add('autoGenerateDtos', CheckboxType::class, [
                'label' => 'Auto-generate DTOs',
                'required' => false,
                'help' => 'Automatically generate PHP DTOs when schemas are promoted to master.',
            ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, $this->onPreSubmit(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ApiToken::class,
        ]);
    }

    /**
     * Clear learning-specific fields when mode is not Learning.
     */
    private function onPreSubmit(FormEvent $event): void
    {
        /** @var array<string, mixed> $data */
        $data = $event->getData();

        $mode = $data['mode'] ?? null;

        if ($mode !== TokenMode::Learning->value) {
            $data['learningThreshold'] = null;
            $data['autoSwitchToValidating'] = false;
            $event->setData($data);
        }
    }
}
