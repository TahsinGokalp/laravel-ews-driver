<?php

namespace TahsinGokalp\LaravelEwsDriver\Tests\Unit;

use jamesiarmes\PhpEws\ArrayType\ArrayOfResponseMessagesType;
use jamesiarmes\PhpEws\Client;
use jamesiarmes\PhpEws\Enumeration\BodyTypeType;
use jamesiarmes\PhpEws\Enumeration\DistinguishedPropertySetType;
use jamesiarmes\PhpEws\Enumeration\ImportanceChoicesType;
use jamesiarmes\PhpEws\Enumeration\MapiPropertyTypeType;
use jamesiarmes\PhpEws\Enumeration\ResponseClassType;
use jamesiarmes\PhpEws\Request\CreateItemType;
use jamesiarmes\PhpEws\Response\CreateItemResponseType;
use jamesiarmes\PhpEws\Response\ItemInfoResponseMessageType;
use jamesiarmes\PhpEws\Type\EmailAddressType;
use jamesiarmes\PhpEws\Type\ExtendedPropertyType;
use jamesiarmes\PhpEws\Type\MessageType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
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

    public function test_it_creates_client_from_config_without_ssl_verification_by_default(): void
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

    public function test_it_verifies_ssl_when_enabled(): void
    {
        $config = $this->makeConfig();
        $config->verifySsl = true;
        $transport = new ExchangeTransport($config);

        $client = (new ReflectionMethod($transport, 'createClient'))->invoke($transport);

        $this->assertSame(
            [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2],
            $this->readProperty($client, 'curl_options')
        );
    }

    public function test_it_sets_disposition_and_subject(): void
    {
        $this->send($this->makeEmail()->subject('Hello there'));

        $this->assertSame('SendAndSaveCopy', $this->capturedRequest->MessageDisposition);
        $this->assertSame('Hello there', $this->sentMessage()->Subject);
    }

    public function test_sender_comes_from_the_message(): void
    {
        $this->send($this->makeEmail()->from(new Address('someone-else@example.com', 'Someone Else')));

        $mailbox = $this->sentMessage()->From->Mailbox;
        $this->assertSame('someone-else@example.com', $mailbox->EmailAddress);
        $this->assertSame('Someone Else', $mailbox->Name);
    }

    public function test_sender_falls_back_to_config_when_message_has_no_from(): void
    {
        $this->send((new Email())->sender('envelope@example.com')->to('to@example.com')->text('Body'));

        $mailbox = $this->sentMessage()->From->Mailbox;
        $this->assertSame('sender@example.com', $mailbox->EmailAddress);
        $this->assertNull($mailbox->Name);
    }

    public function test_sender_is_omitted_when_neither_message_nor_config_has_one(): void
    {
        $config = $this->makeConfig();
        $config->from = null;

        $this->send((new Email())->sender('envelope@example.com')->to('to@example.com')->text('Body'), config: $config);

        $this->assertNull($this->sentMessage()->From);
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
        $this->assertNull($attachments[0]->IsInline);
        $this->assertNull($attachments[0]->ContentId);

        $this->assertSame('invoice.pdf', $attachments[1]->Name);
        $this->assertSame('application/pdf', $attachments[1]->ContentType);
        $this->assertSame('%PDF-1.4 fake', $attachments[1]->Content);
    }

    public function test_it_marks_embedded_images_inline_and_rewrites_cid_references(): void
    {
        $image = (new DataPart('png-bytes', 'logo.png', 'image/png'))->asInline();

        $this->send(
            $this->makeEmail()
                ->html('<img src="cid:logo.png"> <img src=cid:logo.png>')
                ->addPart($image)
        );

        $message = $this->sentMessage();
        $attachment = $message->Attachments->FileAttachment[0];
        $contentId = $image->getContentId();

        $this->assertTrue($attachment->IsInline);
        $this->assertSame($contentId, $attachment->ContentId);
        $this->assertSame('logo.png', $attachment->Name);
        $this->assertSame('png-bytes', $attachment->Content);
        $this->assertSame("<img src=\"cid:$contentId\"> <img src=cid:$contentId>", $message->Body->_);
    }

    public function test_it_keeps_references_made_by_content_id(): void
    {
        $image = (new DataPart('png-bytes', 'logo.png', 'image/png'))->asInline()->setContentId('logo@example.com');

        $this->send($this->makeEmail()->html('<img src="cid:logo@example.com">')->addPart($image));

        $message = $this->sentMessage();
        $this->assertTrue($message->Attachments->FileAttachment[0]->IsInline);
        $this->assertSame('logo@example.com', $message->Attachments->FileAttachment[0]->ContentId);
        $this->assertSame('<img src="cid:logo@example.com">', $message->Body->_);
    }

    public function test_attachment_referenced_by_cid_is_treated_as_inline(): void
    {
        $this->send(
            $this->makeEmail()
                ->html('<img src="cid:chart.png">')
                ->attach('png-bytes', 'chart.png', 'image/png')
                ->attach('pdf-bytes', 'report.pdf', 'application/pdf')
        );

        [$chart, $report] = $this->sentMessage()->Attachments->FileAttachment;

        $this->assertTrue($chart->IsInline);
        $this->assertNotEmpty($chart->ContentId);
        $this->assertSame('<img src="cid:'.$chart->ContentId.'">', $this->sentMessage()->Body->_);
        $this->assertNull($report->IsInline);
    }

    public function test_it_reads_stream_bodies(): void
    {
        $html = fopen('php://memory', 'r+');
        fwrite($html, '<p>Streamed</p>');

        $this->send($this->makeEmail()->html($html));

        $this->assertSame('<p>Streamed</p>', $this->sentMessage()->Body->_);
    }

    public function test_it_maps_reply_to(): void
    {
        $this->send($this->makeEmail()->replyTo(new Address('support@example.com', 'Support'), 'help@example.com'));

        $mailboxes = $this->sentMessage()->ReplyTo->Mailbox;

        $this->assertCount(2, $mailboxes);
        $this->assertSame('support@example.com', $mailboxes[0]->EmailAddress);
        $this->assertSame('Support', $mailboxes[0]->Name);
        $this->assertSame('help@example.com', $mailboxes[1]->EmailAddress);
    }

    public function test_it_leaves_optional_header_fields_empty_by_default(): void
    {
        $this->send($this->makeEmail());

        $message = $this->sentMessage();
        $this->assertNull($message->ReplyTo);
        $this->assertNull($message->Importance);
        $this->assertNull($message->IsReadReceiptRequested);
        $this->assertNull($message->IsDeliveryReceiptRequested);
        $this->assertNull($message->InReplyTo);
        $this->assertNull($message->References);
        $this->assertSame([], $message->ExtendedProperty);
    }

    #[DataProvider('priorities')]
    public function test_it_maps_priority_to_importance(int $priority, string $importance): void
    {
        $this->send($this->makeEmail()->priority($priority));

        $this->assertSame($importance, $this->sentMessage()->Importance);
    }

    public static function priorities(): array
    {
        return [
            'highest' => [Email::PRIORITY_HIGHEST, ImportanceChoicesType::HIGH],
            'high' => [Email::PRIORITY_HIGH, ImportanceChoicesType::HIGH],
            'normal' => [Email::PRIORITY_NORMAL, ImportanceChoicesType::NORMAL],
            'low' => [Email::PRIORITY_LOW, ImportanceChoicesType::LOW],
            'lowest' => [Email::PRIORITY_LOWEST, ImportanceChoicesType::LOW],
        ];
    }

    public function test_it_requests_read_and_delivery_receipts(): void
    {
        $email = $this->makeEmail();
        $email->getHeaders()->addMailboxListHeader('Disposition-Notification-To', ['app@example.com']);
        $email->getHeaders()->addMailboxListHeader('Return-Receipt-To', ['app@example.com']);

        $this->send($email);

        $message = $this->sentMessage();
        $this->assertTrue($message->IsReadReceiptRequested);
        $this->assertTrue($message->IsDeliveryReceiptRequested);
        $this->assertSame([], $message->ExtendedProperty);
    }

    public function test_it_maps_threading_headers(): void
    {
        $email = $this->makeEmail();
        $email->getHeaders()->addIdHeader('In-Reply-To', 'parent@example.com');
        $email->getHeaders()->addIdHeader('References', ['root@example.com', 'parent@example.com']);

        $this->send($email);

        $message = $this->sentMessage();
        $this->assertSame('<parent@example.com>', $message->InReplyTo);
        $this->assertSame('<root@example.com> <parent@example.com>', $message->References);
    }

    public function test_it_forwards_custom_headers_as_internet_headers(): void
    {
        $email = $this->makeEmail();
        $email->getHeaders()
            ->addTextHeader('X-Campaign', 'autumn-sale')
            ->addTextHeader('List-Unsubscribe', '<https://example.com/unsubscribe>');

        $this->send($email);

        $properties = $this->sentMessage()->ExtendedProperty;

        $this->assertCount(2, $properties);
        $this->assertContainsOnlyInstancesOf(ExtendedPropertyType::class, $properties);

        $this->assertSame(DistinguishedPropertySetType::INTERNET_HEADERS, $properties[0]->ExtendedFieldURI->DistinguishedPropertySetId);
        $this->assertSame(MapiPropertyTypeType::STRING, $properties[0]->ExtendedFieldURI->PropertyType);
        $this->assertSame('X-Campaign', $properties[0]->ExtendedFieldURI->PropertyName);
        $this->assertSame('autumn-sale', $properties[0]->Value);

        $this->assertSame('List-Unsubscribe', $properties[1]->ExtendedFieldURI->PropertyName);
        $this->assertSame('<https://example.com/unsubscribe>', $properties[1]->Value);
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

    public function test_it_wraps_client_errors_in_a_transport_exception(): void
    {
        $previous = new \Exception('SOAP client returned status of 401.', 401);

        try {
            $this->sendWithClient($this->makeEmail(), $this->failingClient($previous));
            $this->fail('Expected an EwsException.');
        } catch (EwsException $e) {
            $this->assertInstanceOf(TransportExceptionInterface::class, $e);
            $this->assertSame('Exchange request failed: SOAP client returned status of 401.', $e->getMessage());
            $this->assertSame(401, $e->getCode());
            $this->assertSame($previous, $e->getPrevious());
        }
    }

    public function test_failover_transport_moves_on_when_exchange_fails(): void
    {
        $exchange = new FakeExchangeTransport(
            $this->makeConfig(),
            $this->failingClient(new \RuntimeException('Curl error: Could not connect to server'))
        );
        $backup = new NullTransport();

        $sent = (new FailoverTransport([$exchange, $backup]))->send($this->makeEmail());

        $this->assertNotNull($sent);
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

    private function send(Email $email, ?CreateItemResponseType $response = null, ?EwsDriverConfig $config = null)
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('CreateItem')
            ->willReturnCallback(function (CreateItemType $request) use ($response) {
                $this->capturedRequest = $request;

                return $response ?? $this->makeResponse([[ResponseClassType::SUCCESS, 'NoError', null]]);
            });

        return (new FakeExchangeTransport($config ?? $this->makeConfig(), $client))->send($email);
    }

    private function sendWithClient(Email $email, Client $client)
    {
        return (new FakeExchangeTransport($this->makeConfig(), $client))->send($email);
    }

    private function failingClient(\Throwable $error): Client
    {
        $client = $this->createMock(Client::class);
        $client->method('CreateItem')->willThrowException($error);

        return $client;
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
