<?php

/*
 * Application-side fixtures. They live in the App\ namespace on purpose: the agent's default
 * deny list skips its own namespace (Cyberwizard\WatchtowerAgent\*), like a real app would.
 */

namespace App\Models {

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Notifications\Notifiable;

    class Order extends Model
    {
        protected $guarded = [];
    }

    class Customer extends Model
    {
        use Notifiable;

        protected $guarded = [];

        public function routeNotificationForVonage(): string
        {
            return '+15550100';
        }
    }
}

namespace App\Events {

    use App\Models\Order;
    use Illuminate\Broadcasting\Channel;
    use Illuminate\Broadcasting\InteractsWithSockets;
    use Illuminate\Broadcasting\PresenceChannel;
    use Illuminate\Broadcasting\PrivateChannel;
    use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
    use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
    use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
    use Illuminate\Foundation\Events\Dispatchable;

    class OrderShipped
    {
        use Dispatchable;

        public string $password = 'hunter2';

        /** @param array<string, mixed> $meta */
        public function __construct(public Order $order, public array $meta = []) {}
    }

    class OrderCancelled
    {
        public function __construct(public int $orderId = 1) {}
    }

    class OrderCommitted implements ShouldDispatchAfterCommit
    {
        public function __construct(public int $orderId = 1) {}
    }

    class PodcastPublished implements ShouldBroadcast
    {
        use InteractsWithSockets;

        public function __construct(public int $podcastId = 7, public string $apiToken = 'tok_live_123') {}

        public function broadcastOn(): array
        {
            return [new Channel('podcasts'), new PrivateChannel('user.1'), new PresenceChannel('studio')];
        }

        public function broadcastAs(): string
        {
            return 'podcast.published';
        }
    }

    class PodcastPublishedNow extends PodcastPublished implements ShouldBroadcastNow {}

    class BrokenBroadcast implements ShouldBroadcast
    {
        public function broadcastOn(): array
        {
            throw new \RuntimeException('No channels today');
        }
    }
}

namespace App\Listeners {

    use App\Events\OrderCancelled;
    use App\Events\OrderShipped;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Events\Dispatcher;
    use RuntimeException;

    class Calls
    {
        /** @var list<string> */
        public static array $calls = [];
    }

    class SendShipmentNotification
    {
        public function handle(OrderShipped $event): void
        {
            usleep(1000);
            Calls::$calls[] = 'notification:'.$event->order->getKey();
        }
    }

    class FailingListener
    {
        public function handle(object $event): void
        {
            throw new RuntimeException('Listener exploded with password=hunter2');
        }
    }

    class StopPropagation
    {
        public function handle(object $event): bool
        {
            Calls::$calls[] = 'stop';

            return false;
        }
    }

    class QueuedShipmentListener implements ShouldQueue
    {
        public function handle(OrderShipped $event): void
        {
            Calls::$calls[] = 'queued:'.$event->order->getKey();
        }
    }

    class FailingQueuedListener implements ShouldQueue
    {
        public $tries = 1;

        public function handle(OrderCancelled $event): void
        {
            throw new RuntimeException('Queued listener failed');
        }
    }

    class OrderSubscriber
    {
        public function onCancelled(OrderCancelled $event): void
        {
            Calls::$calls[] = 'subscriber:'.$event->orderId;
        }

        public function subscribe(Dispatcher $events): array
        {
            return [OrderCancelled::class => 'onCancelled'];
        }
    }
}

namespace App\Broadcasting {

    use Illuminate\Broadcasting\Broadcasters\Broadcaster;
    use Illuminate\Broadcasting\BroadcastException;

    class FailingBroadcaster extends Broadcaster
    {
        public function auth($request)
        {
            return true;
        }

        public function validAuthenticationResponse($request, $result)
        {
            return $result;
        }

        public function broadcast(array $channels, $event, array $payload = [])
        {
            throw new BroadcastException('Pusher error: 503 Service Unavailable.');
        }
    }
}

namespace App\Mail {

    use Illuminate\Mail\Mailable;
    use Symfony\Component\Mailer\Envelope;
    use Symfony\Component\Mailer\Exception\TransportException;
    use Symfony\Component\Mailer\SentMessage;
    use Symfony\Component\Mailer\Transport\TransportInterface;
    use Symfony\Component\Mime\RawMessage;

    class OrderShippedMail extends Mailable
    {
        public function build(): static
        {
            return $this->subject('Your order shipped')
                ->html('<p>Hello Jane, your reset token=abc123 is inside.</p>')
                ->text('mail.plain', []);
        }
    }

    class FailingTransport implements TransportInterface
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException('Connection to smtp.example.com:587 refused');
        }

        public function __toString(): string
        {
            return 'failing://';
        }
    }
}

namespace App\Notifications {

    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Notifications\Messages\MailMessage;
    use Illuminate\Notifications\Notification;

    class OrderShippedNotification extends Notification
    {
        use Queueable;

        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('Shipped!')->line('Your order shipped.');
        }
    }

    class QueuedOrderShippedNotification extends OrderShippedNotification implements ShouldQueue {}

    class SmsAndDatabaseNotification extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['database', 'vonage'];
        }
    }

    class FailingChannelNotification extends Notification
    {
        public function via(object $notifiable): array
        {
            return [FailingChannel::class];
        }
    }

    class FailingChannel
    {
        public function send(object $notifiable, Notification $notification): void
        {
            throw new \RuntimeException('SMTP said no to jane@example.com with api_key=sk_live_123');
        }
    }
}
