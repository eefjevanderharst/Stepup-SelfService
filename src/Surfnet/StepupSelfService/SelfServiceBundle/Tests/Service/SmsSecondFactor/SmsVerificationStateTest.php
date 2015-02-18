<?php

/**
 * Copyright 2014 SURFnet bv
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Surfnet\StepupSelfService\SelfServiceBundle\Tests\Service\SmsSecondFactor;

use DateInterval;
use DateTime;
use PHPUnit_Framework_TestCase as TestCase;
use Surfnet\StepupSelfService\SelfServiceBundle\Service\SmsSecondFactor\SmsVerificationState;
use Surfnet\StepupSelfService\SelfServiceBundle\Tests\DateTimeHelper;

/**
 * @runTestsInSeparateProcesses
 */
class SmsVerificationStateTest extends TestCase
{
    public function non_strings()
    {
        return [
            'array'        => [array()],
            'integer'      => [1],
            'object'       => [new \stdClass()],
            'null'         => [null],
            'bool'         => [false],
            'resource'     => [fopen('php://memory', 'w')],
        ];
    }

    public function non_non_empty_strings()
    {
        return [
            'empty string' => [''],
            'array'        => [array()],
            'integer'      => [1],
            'object'       => [new \stdClass()],
            'null'         => [null],
            'bool'         => [false],
            'resource'     => [fopen('php://memory', 'w')],
        ];
    }

    /**
     * @test
     * @group sms
     */
    public function it_can_be_matched()
    {
        $state = new SmsVerificationState(new DateInterval('PT15M'), 3);
        $otp = $state->requestNewOtp('123');

        $this->assertTrue($state->verify($otp)->wasSuccessful(), 'OTP should have matched');
    }

    /**
     * @test
     * @group sms
     * @dataProvider non_non_empty_strings
     * @param mixed $nonString
     */
    public function it_accepts_only_string_phone_numbers($nonString)
    {
        $this->setExpectedException(
            'Surfnet\StepupSelfService\SelfServiceBundle\Exception\InvalidArgumentException',
            'phoneNumber'
        );
        $state = new SmsVerificationState(new DateInterval('PT15M'), 3);
        $state->requestNewOtp($nonString);
    }

    /**
     * @test
     * @group sms
     * @dataProvider non_strings
     * @param mixed $nonString
     */
    public function it_verifies_only_string_otps($nonString)
    {
        $this->setExpectedException(
            'Surfnet\StepupSelfService\SelfServiceBundle\Exception\InvalidArgumentException',
            'userOtp'
        );
        $state = new SmsVerificationState(new DateInterval('PT15M'), 3);
        $state->requestNewOtp('123');
        $state->verify($nonString);
    }

    /**
     * @test
     * @group sms
     */
    public function it_can_expire()
    {
        DateTimeHelper::setCurrentTime(new DateTime('@0'));
        $state = new SmsVerificationState(new DateInterval('PT1S'), 3);
        $otp = $state->requestNewOtp('123');

        DateTimeHelper::setCurrentTime(new DateTime('@1'));
        $verification = $state->verify($otp);

        $this->assertFalse($verification->wasSuccessful(), "Verification shouldn't be successful");
        $this->assertTrue($verification->didOtpExpire(), "OTP should have expired");
        $this->assertTrue($verification->didOtpMatch(), "OTP should have matched");
    }

    /**
     * @test
     * @group sms
     */
    public function the_expiration_time_is_pushed_back_with_each_new_otp()
    {
        // Set a challenge
        DateTimeHelper::setCurrentTime(new DateTime('@0'));
        $state = new SmsVerificationState(new DateInterval('PT5S'), 3);
        $otp = $state->requestNewOtp('123');

        // Try after 3 seconds
        DateTimeHelper::setCurrentTime(new DateTime('@3'));
        $this->assertTrue($state->verify($otp)->wasSuccessful(), "OTP should've matched");

        // Set a new challenge
        $otp = $state->requestNewOtp('123');

        // Try after 4 seconds (total of 7 seconds, longer than 5-second expiry interval)
        DateTimeHelper::setCurrentTime(new DateTime('@7'));
        $this->assertTrue($state->verify($otp)->wasSuccessful(), "OTP should've matched");
    }

    /**
     * @test
     * @group sms
     */
    public function the_consumer_can_request_too_many_otps_but_can_keep_track_of_remaining_requests()
    {
        $state = new SmsVerificationState(new DateInterval('PT10S'), 3);
        $this->assertSame(3, $state->getOtpRequestsRemainingCount());

        $state->requestNewOtp('123');
        $this->assertSame(2, $state->getOtpRequestsRemainingCount());

        $state->requestNewOtp('123');
        $this->assertSame(1, $state->getOtpRequestsRemainingCount());

        $state->requestNewOtp('123');
        $this->assertSame(0, $state->getOtpRequestsRemainingCount());
        $this->assertSame(0, $state->getOtpRequestsRemainingCount());

        $this->setExpectedException(
            'Surfnet\StepupSelfService\SelfServiceBundle\Service\Exception\TooManyChallengesRequestedException'
        );
        $state->requestNewOtp('123');
        $this->assertSame(0, $state->getOtpRequestsRemainingCount());
    }

    public function lteZeroMaximumTries()
    {
        return [[0], [-1], [-1000]];
    }

    /**
     * @test
     * @group sms
     * @dataProvider lteZeroMaximumTries
     * @param int $maximumTries
     */
    public function maximum_challenges_must_be_gte_1($maximumTries)
    {
        $this->setExpectedException(
            'Surfnet\StepupSelfService\SelfServiceBundle\Exception\InvalidArgumentException',
            'maximum OTP requests'
        );

        new SmsVerificationState(new DateInterval('PT15M'), $maximumTries);
    }

    /**
     * @test
     * @group sms
     */
    public function a_previous_otp_can_be_matched()
    {
        DateTimeHelper::setCurrentTime(new DateTime('@0'));
        $state = new SmsVerificationState(new DateInterval('PT5S'), 3);
        $otp1 = $state->requestNewOtp('123');
        $otp2 = $state->requestNewOtp('123');

        $this->assertTrue($state->verify($otp1)->wasSuccessful(), "OTP should've matched");
        $this->assertTrue($state->verify($otp2)->wasSuccessful(), "OTP should've matched");
    }

    /**
     * @test
     * @group sms
     */
    public function otp_matching_is_case_insensitive()
    {
        DateTimeHelper::setCurrentTime(new DateTime('@0'));
        $state = new SmsVerificationState(new DateInterval('PT5S'), 3);
        $otp = $state->requestNewOtp('123');

        $this->assertTrue($state->verify(strtolower($otp))->wasSuccessful(), "OTP should've matched");
        $this->assertTrue($state->verify(strtoupper($otp))->wasSuccessful(), "OTP should've matched");
    }
}
