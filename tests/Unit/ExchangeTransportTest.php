<?php

namespace TahsinGokalp\LaravelEwsDriver\Tests\Unit;

use jamesiarmes\PhpEws\ArrayType\ArrayOfResponseMessagesType;
use jamesiarmes\PhpEws\Client;
use jamesiarmes\PhpEws\Enumeration\BodyTypeType;
use jamesiarmes\PhpEws\Enumeration\ResponseClassType;
use jamesiarmes\PhpEws\Request\CreateItemType;
use jamesiarmes\PhpEws\Response\CreateItemResponseType;
use jamesiarmes\PhpEws\Response\ItemInfoResponseMessageType;
use jamesiarmes\PhpEws\Type\EmailAddressType;
use jamesiarmes\PhpEws\Type\MessageType;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use TahsinGokalp\LaravelEwsDriver\Config\EwsDriverConfig;
use TahsinGokalp\LaravelEwsDriver\Exceptions\EwsException;
use TahsinGokalp\LaravelEwsDriver\Tests\Fakes\FakeExchangeTransport;
use TahsinGokalp\LaravelEwsDriver\Transport\ExchangeTransport;

class ExchangeTransportTest extends TestCase
{
    private ?CreateItemType $capturedRequest = null;

    public function test_it_is_named_exchange(): void
    {
        $this->assertSame('exchange', (string) new ExchangeTransport($this->makeConfig()));
    }

    public function test_it_creates_client_from_config(): void
    {
        $transport = new ExchangeTransport($this->makeConfig());

        $client = (new ReflectionMethod($transport, 'createClient'))->invoke($transport);

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame('webmail.example.com', $this->readProperty($client, 'server'));
        $this->assertSame('example-user', $this->readProperty($client, 'username'));
        $this->assertSame('example-password', $this->readProperty($client, 'password'));
        $this->assertSame('Exchange2010', $this->readProperty($client, 'version'));
        $this->assertSame([CURLOPT_SSL_VERIFYPEER => false], $this->readProperty($client, 'curl_options'));
    }

    public function test_it_sets_disposition_subject_and_sender(): void
    {
        $this->send($this->makeEmail()->subject('Hello there'));

        $this->assertSame('SendAndSaveCopy', $this->capturedRequest->MessageDisposition);

        $message = $this->sentMessage();
        $this->assertSame('Hello there', $message->Subject);
        $this->assertSame('sender@example.com', $message->From->Mailbox->EmailAddress);
    }

    public function test_sender_comes_from_config_instead_of_the_message(): void
    {
        $this->send($this->makeEmail()->from('someone-else@example.com'));

        $this->assertSame('sender@example.com', $this->sentMessage()->From->Mailbox->EmailAddress);
    }

    public function test_it_maps_to_recipients_with_names(): void
    {
        $this->send(
            (new Email())
                ->from('app@example.com')
                ->to(new Address('jane@example.com', 'Jane Doe'), 'john@example.com')
                ->text('Body')
        );

        $mailboxes = $this->sentMessage()->ToRecipients->Mailbox;

        $this->assertCount(2, $mailboxes);
        $this->assertContainsOnlyInstancesOf(EmailAddressType::class, $mailboxes);
        $this->assertSame('jane@example.com', $mailboxes[0]->EmailAddress);
        $this->assertSame('Jane Doe', $mailboxes[0]->Name);
        $this->assertSame('john@example.com', $mailboxes[1]->EmailAddress);
        $this->assertNull($mailboxes[1]->Name);
    }

    public function test_it_omits_cc_and_bcc_when_not_given(): void
    {
        $this->send($this->makeEmail());

        $message = $this->sentMessage();
        $this->assertNull($message->CcRecipients);
        $this->assertNull($message->BccRecipients);
    }

    public function test_it_maps_cc_and_bcc_recipients(): void
    {
        $this->send(
            $this->makeEmail()
                ->cc(new Address('cc1@example.com', 'Cc One'), 'cc2@example.com')
                ->bcc('bcc@example.com')
        );

        $message = $this->sentMessage();

        $this->assertCount(2, $message->CcRecipients->Mailbox);
        $this->assertSame('cc1@example.com', $message->CcRecipients->Mailbox[0]->EmailAddress);
        $this->assertSame('Cc One', $message->CcRecipients->Mailbox[0]->Name);
        $this->assertSame('cc2@example.com', $message->CcRecipients->Mailbox[1]->EmailAddress);

        $this->assertCount(1, $message->BccRecipients->Mailbox);
        $this->assertSame('bcc@example.com', $message->BccRecipients->Mailbox[0]->EmailAddress);
    }

    public function test_it_sends_text_body_when_there_is_no_html(): void
    {
        $this->send($this->makeEmail()->text('Plain text body'));

        $body = $this->sentMessage()->Body;
        $this->assertSame(BodyTypeType::TEXT, $body->BodyType);
        $this->assertSame('Plain text body', $body->_);
    }

    public function test_it_prefers_html_body_over_text(): void
    {
        $this->send($this->makeEmail()->text('Plain text body')->html('<p>HTML body</p>'));

        $body = $this->sentMessage()->Body;
        $this->assertSame(BodyTypeType::HTML, $body->BodyType);
        $this->assertSame('<p>HTML body</p>', $body->_);
    }

    public function test_it_omits_attachments_when_there_are_none(): void
    {
        $this->send($this->makeEmail());

        $this->assertNull($this->sentMessage()->Attachments);
    }

    public function test_it_maps_attachments(): void
    {
        $this->send(
            $this->makeEmail()
                ->attach('first file contents', 'report.txt', 'text/plain')
                ->attach('%PDF-1.4 fake', 'invoice.pdf', 'application/pdf')
        );

        $attachments = $this->sentMessage()->Attachments->FileAttachment;

        $this->assertCount(2, $attachments);

        $this->assertSame('report.txt', $attachments[0]->Name);
        $this->assertSame('text/plain', $attachments[0]->ContentType);
        $this->assertSame('first file contents', $attachments[0]->Content);
        $this->assertNotEmpty($attachments[0]->ContentId);

        $this->assertSame('invoice.pdf', $attachments[1]->Name);
        $this->assertSame('application/pdf', $attachments[1]->ContentType);
        $this->assertSame('%PDF-1.4 fake', $attachments[1]->Content);
    }

    public function test_it_sends_a_single_message_per_request(): void
    {
        $this->send($this->makeEmail());

        $this->assertCount(1, $this->capturedRequest->Items->Message);
    }

    public function test_it_throws_when_exchange_returns_an_error(): void
    {
        $this->expectException(EwsException::class);
        $this->expectExceptionMessage('Message failed to create with "ErrorAccessDenied: Access is denied."');

        $this->send($this->makeEmail(), $this->makeResponse([
            [ResponseClassType::ERROR, 'ErrorAccessDenied', 'Access is denied.'],
        ]));
    }

    public function test_it_throws_when_exchange_returns_a_warning(): void
    {
        $this->expectException(EwsException::class);
        $this->expectExceptionMessage('ErrorBatchProcessingStopped');

        $this->send($this->makeEmail(), $this->makeResponse([
            [ResponseClassType::WARNING, 'ErrorBatchProcessingStopped', 'Batch stopped.'],
        ]));
    }

    public function test_it_throws_when_any_response_message_fails(): void
    {
        $this->expectException(EwsException::class);
        $this->expectExceptionMessage('ErrorQuotaExceeded');

        $this->send($this->makeEmail(), $this->makeResponse([
            [ResponseClassType::SUCCESS, 'NoError', null],
            [ResponseClassType::ERROR, 'ErrorQuotaExceeded', 'Quota exceeded.'],
        ]));
    }

    public function test_it_returns_sent_message_on_success(): void
    {
        $sent = $this->send($this->makeEmail());

        $this->assertNotNull($sent);
        $this->assertSame(['to@example.com'], array_map(
            fn (Address $address) => $address->getAddress(),
            $sent->getEnvelope()->getRecipients()
        ));
    }

    private function send(Email $email, ?CreateItemResponseType $response = null)
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('CreateItem')
            ->willReturnCallback(function (CreateItemType $request) use ($response) {
                $this->capturedRequest = $request;

                return $response ?? $this->makeResponse([[ResponseClassType::SUCCESS, 'NoError', null]]);
            });

        return (new FakeExchangeTransport($this->makeConfig(), $client))->send($email);
    }

    private function sentMessage(): MessageType
    {
        $this->assertNotNull($this->capturedRequest, 'No request was sent to Exchange.');

        return $this->capturedRequest->Items->Message[0];
    }

    private function makeEmail(): Email
    {
        return (new Email())
            ->from('app@example.com')
            ->to('to@example.com')
            ->subject('Subject')
            ->text('Body');
    }

    private function makeConfig(): EwsDriverConfig
    {
        $config = new EwsDriverConfig();
        $config->from = 'sender@example.com';
        $config->host = 'webmail.example.com';
        $config->username = 'example-user';
        $config->password = 'example-password';
        $config->version = 'Exchange2010';
        $config->messageDispositionType = 'SendAndSaveCopy';

        return $config;
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: ?string}> $messages
     */
    private function makeResponse(array $messages): CreateItemResponseType
    {
        $response = new CreateItemResponseType();
        $response->ResponseMessages = new ArrayOfResponseMessagesType();

        foreach ($messages as [$class, $code, $text]) {
            $message = new ItemInfoResponseMessageType();
            $message->ResponseClass = $class;
            $message->ResponseCode = $code;
            $message->MessageText = $text;

            $response->ResponseMessages->CreateItemResponseMessage[] = $message;
        }

        return $response;
    }

    private function readProperty(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }
}
