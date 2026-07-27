<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaStepUpProof;
use MyInvoice\Service\Auth\MfaStepUpProofStore;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\StepUpOperationException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class MfaStepUpServiceTest extends TestCase
{
    public function testUnknownOperationIsRejectedBeforeProofIssuance(): void
    {
        $proofs = $this->createMock(MfaStepUpProofStore::class);
        $proofs->expects(self::never())->method('issue');
        $service = new MfaStepUpService(
            $proofs,
            $this->createMock(MfaPolicyService::class),
            $this->createMock(PasskeyCredentialRepository::class),
        );

        $this->expectException(StepUpOperationException::class);
        $service->issue(17, 'session', 'admin.anything', 'totp');
    }

    public function testPhasedOutTotpCanAuthorizePasskeyEnrollmentOnly(): void
    {
        $proofs = $this->createMock(MfaStepUpProofStore::class);
        $proofs->expects(self::once())
            ->method('issue')
            ->with(17, 'session', MfaStepUpService::OPERATION_PASSKEY_REGISTER, 'totp', null)
            ->willReturn('proof-token');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::exactly(2))
            ->method('isMethodAllowed')
            ->willReturnMap([
                ['totp', false],
                ['passkey', true],
            ]);
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('countActiveForUser')
            ->with(17)
            ->willReturn(0);
        $service = new MfaStepUpService(
            $proofs,
            $policy,
            $credentials,
        );

        self::assertSame(
            'proof-token',
            $service->issue(
                17,
                'session',
                MfaStepUpService::OPERATION_PASSKEY_REGISTER,
                'totp',
            ),
        );
    }

    public function testPhasedOutTotpCannotAuthorizeAnotherPasskeyEnrollment(): void
    {
        $proofs = $this->createMock(MfaStepUpProofStore::class);
        $proofs->expects(self::never())->method('issue');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::exactly(2))
            ->method('isMethodAllowed')
            ->willReturnMap([
                ['totp', false],
                ['passkey', true],
            ]);
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('countActiveForUser')
            ->with(17)
            ->willReturn(1);
        $service = new MfaStepUpService($proofs, $policy, $credentials);

        $this->expectException(StepUpOperationException::class);
        $service->issue(
            17,
            'session',
            MfaStepUpService::OPERATION_PASSKEY_REGISTER,
            'totp',
        );
    }

    public function testTotpCannotAuthorizeEnrollmentWhenPasskeysAreNotAllowed(): void
    {
        $proofs = $this->createMock(MfaStepUpProofStore::class);
        $proofs->expects(self::never())->method('issue');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::exactly(2))
            ->method('isMethodAllowed')
            ->willReturnMap([
                ['totp', true],
                ['passkey', false],
            ]);
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::never())->method('countActiveForUser');
        $service = new MfaStepUpService($proofs, $policy, $credentials);

        $this->expectException(StepUpOperationException::class);
        $service->issue(
            17,
            'session',
            MfaStepUpService::OPERATION_PASSKEY_REGISTER,
            'totp',
        );
    }

    public function testDisallowedMethodCannotAuthorizeApiTokenCreation(): void
    {
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())->method('isMethodAllowed')->with('totp')->willReturn(false);
        $service = new MfaStepUpService(
            $this->createMock(MfaStepUpProofStore::class),
            $policy,
            $this->createMock(PasskeyCredentialRepository::class),
        );

        $this->expectException(StepUpOperationException::class);
        $service->assertAllowed(17, MfaStepUpService::OPERATION_API_TOKEN_CREATE, 'totp');
    }

    public function testRevokeOperationMustReferenceOwnedActiveCredential(): void
    {
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('findActiveForUserById')
            ->with(17, 42)
            ->willReturn(null);
        $service = new MfaStepUpService(
            $this->createMock(MfaStepUpProofStore::class),
            $this->createMock(MfaPolicyService::class),
            $credentials,
        );

        $this->expectException(StepUpOperationException::class);
        $service->assertAllowed(17, 'passkey.revoke:42', 'passkey');
    }

    public function testConsumedPasskeyProofRequiresAuthenticationCredentialToRemainActive(): void
    {
        $proofs = $this->createMock(MfaStepUpProofStore::class);
        $proofs->expects(self::once())
            ->method('consume')
            ->with('proof', 17, 'session', MfaStepUpService::OPERATION_API_TOKEN_CREATE)
            ->willReturn(new MfaStepUpProof(
                17,
                MfaStepUpService::OPERATION_API_TOKEN_CREATE,
                'passkey',
                42,
            ));
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())->method('isMethodAllowed')->with('passkey')->willReturn(true);
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('findActiveForUserById')
            ->with(17, 42)
            ->willReturn(null);
        $service = new MfaStepUpService($proofs, $policy, $credentials);

        $this->expectException(StepUpOperationException::class);
        $service->consume(
            'proof',
            17,
            'session',
            MfaStepUpService::OPERATION_API_TOKEN_CREATE,
        );
    }
}
