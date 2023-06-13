<?php

namespace Grandeljay\Availability;

use Discord\Builders\Components\{Button, ActionRow};
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\User;
use Discord\WebSockets\Event;
use Grandeljay\Availability\Commands\Command;

class Bot
{
    public const TIME_DEFAULT = '19:00';
    public const DATE_DEFAULT = 'monday ' . self::TIME_DEFAULT;

    protected Discord $discord;
    protected Config $config;
    protected Action $action;

    /**
     * Returns the unix timestamp from a user specified date/time.
     *
     * @param string $message The user's date/time input.
     *
     * @return int|false The unix timestamp on success or `false`.
     */
    public static function getTimeFromString(string $message): int|false
    {
        $message = str_replace(
            array('next week', 'next time', 'next ', 'on ', 'at '),
            array(self::DATE_DEFAULT, self::DATE_DEFAULT, '', '', ''),
            $message
        );

        $time = strtotime($message);

        if ('00:00' === date('H:i', $time)) {
            $time += 19 * 3600;
        }

        return $time;
    }

    /**
     * Construct
     */
    public function __construct()
    {
        $this->config  = new Config();
        $this->discord = new Discord(
            array(
                'token' => $this->config->getAPIToken(),
            )
        );
    }

    /**
     * Removes orphaned commands and adds the active ones.
     *
     * @return void
     */
    public function install(): void
    {
        $this->discord->on(
            'ready',
            function (Discord $discord) {
                /** Remove orphaned commands */
                $discord->application->commands->freshen()->then(
                    function ($commands) use ($discord) {
                        foreach ($commands as $command) {
                            $discord->application->commands->delete($command);
                        }
                    }
                );

                /** Commands */
                $command = new Command(
                    Command::AVAILABILITY,
                    'Shows everybody\'s availability.'
                );
                $command = new Command(
                    Command::AVAILABLE,
                    'Mark yourself as available.'
                );
                $command = new Command(
                    Command::UNAVAILABLE,
                    'Mark yourself as unavailable.'
                );
            }
        );
    }

    public function initialise(): void
    {
        $this->install();

        $this->discord->on(
            Event::MESSAGE_CREATE,
            function (Message $message, Discord $discord) {
                if (!$this->determineIfUnavailable($message, $discord)) {
                    $this->determineIfAvailable($message, $discord);
                }
            }
        );

        $this->discord->run();
    }

    /**
     * Permanently saves the user's specified availability time.
     *
     * @param User $user                 The message author.
     * @param bool $userIsAvailable      Whether the user is available.
     * @param int  $userAvailabilityTime The unix timestamp of the user's
     *                                   availability.
     *
     * @return void
     */
    public function setUserAvailability(User $user, bool $userIsAvailable, int $userAvailabilityTime): void
    {
        $directoryAvailabilities = $this->config->getAvailabiliesDir();

        if (!file_exists($directoryAvailabilities) || !is_dir($directoryAvailabilities)) {
            mkdir($directoryAvailabilities);
        }

        $filename = $user->id . '.json';
        $filepath = $directoryAvailabilities . '/' . $filename;

        $availability = array(
            'userId'                    => $user->id,
            'userName'                  => $user->username,
            'userIsAvailable'           => $userIsAvailable,
            'userAvailabilityTime'      => $userAvailabilityTime,
            'userIsAvailablePerDefault' => false,
        );

        file_put_contents($filepath, json_encode($availability));
    }

    /**
     * Returns whether the current user is subscribed. A user is considered
     * subscribed when the has used the `/available` or `/unavailable` command
     * at least once.
     *
     * @param int $userId The user Id to check.
     *
     * @return boolean
     */
    private function userIsSubscribed(int $userId): bool
    {
        $availabilities   = $this->getAvailabilities();
        $userIsSubscribed = false;

        foreach ($availabilities as $availability) {
            if ((int) $availability['userId'] === $userId) {
                $userIsSubscribed = true;

                break;
            }
        }

        return $userIsSubscribed;
    }

    private function determineIfAvailable(Message $message, Discord $discord): bool
    {
        if (!$this->userIsSubscribed($message->author->id)) {
            return false;
        }

        /** Parse message and determine if it means availability */
        $userAvailabilityPhrase = '';

        if ('' === $userAvailabilityPhrase) {
            $availableKeywordsSingles = array(
                'available',
                'coming',
            );

            foreach ($availableKeywordsSingles as $keyword) {
                if (str_contains($message->content, $keyword)) {
                    $userAvailabilityPhrase .= $keyword . ' ';
                }
            }
        }

        if ('' === $userAvailabilityPhrase) {
            $availableKeywordsPairs = array(
                array(
                    'can',
                    'going to',
                    'able to',
                    'will',
                ),
                array(
                    'be there',
                    'come',
                    'make it',
                ),
            );

            foreach ($availableKeywordsPairs as $keywordsSet) {
                foreach ($keywordsSet as $keyword) {
                    if (str_contains($message->content, $keyword)) {
                        $userAvailabilityPhrase .= $keyword . ' ';
                    }
                }
            }
        }

        $userAvailabilityPhrase = trim($userAvailabilityPhrase);

        if ('' === $userAvailabilityPhrase) {
            return false;
        }

        $userIsAvailable = 1 === preg_match('/' . $userAvailabilityPhrase . ' (.+)/i', $message->content, $matches);

        if (!$userIsAvailable || !isset($matches[1])) {
            return false;
        }

        /** Validate availability time */
        $userAvailableTime = Bot::getTimeFromString($matches[1]);

        if (false === $userAvailableTime || time() >= $userAvailableTime) {
            return false;
        }

        /** Respond with a prompt */
        $actionRow = ActionRow::new()
        ->addComponent(
            Button::new(Button::STYLE_PRIMARY)
            ->setLabel('Yes')
            ->setListener(
                function (Interaction $interaction) use ($userAvailableTime, $message) {
                    $this->setUserAvailability($interaction->user, true, $userAvailableTime);

                    $interaction->message->delete();
                    $message->reply(
                        MessageBuilder::new()
                        ->setContent(
                            sprintf(
                                'Alrighty! You are now officially **available** on `%s` at `%s`.',
                                date('d.m.Y', $userAvailableTime),
                                date('H:i', $userAvailableTime)
                            )
                        )
                        ->_setFlags(Message::FLAG_EPHEMERAL)
                    );
                },
                $discord
            )
        )
        ->addComponent(
            Button::new(Button::STYLE_SECONDARY)
            ->setLabel('No')
            ->setListener(
                function (Interaction $interaction) {
                    $interaction
                    ->respondWithMessage(
                        MessageBuilder::new()
                        ->setContent('Whoops, sorry!'),
                        true
                    );
                    $interaction->message->delete();
                },
                $discord
            )
        );

        $messageReply = MessageBuilder::new()
        ->setContent(
            sprintf(
                'You will be **available** for dota on `%s` at `%s`, did I get that right?',
                date('d.m.Y', $userAvailableTime),
                date('H:i', $userAvailableTime),
            )
        )
        ->_setFlags(Message::FLAG_EPHEMERAL)
        ->addComponent($actionRow);

        $message->reply($messageReply);

        return true;
    }

    private function determineIfUnavailable(Message $message, Discord $discord): bool
    {
        if (!$this->userIsSubscribed($message->author->id)) {
            return false;
        }

        /** Parse message and determine if it means unavailability */
        $userAvailabilityPhrase = '';

        if ('' === $userAvailabilityPhrase) {
            $unavailableKeywordsSingles = array(
                'not available',
                'not coming',
                'unavailable',
            );

            foreach ($unavailableKeywordsSingles as $keyword) {
                if (str_contains($message->content, $keyword)) {
                    $userAvailabilityPhrase .= $keyword . ' ';
                }
            }
        }

        if ('' === $userAvailabilityPhrase) {
            $unavailableKeywordsPairs = array(
                array(
                    'can not',
                    'can\'t',
                    'cannot',
                    'cant',
                    'not going to',
                    'unable to',
                    'will not',
                    'won\'t',
                    'wont',
                ),
                array(
                    'be there',
                    'come',
                    'make it',
                ),
            );

            foreach ($unavailableKeywordsPairs as $keywordsSet) {
                foreach ($keywordsSet as $keyword) {
                    if (str_contains($message->content, $keyword)) {
                        $userAvailabilityPhrase .= $keyword . ' ';
                    }
                }
            }
        }

        $userAvailabilityPhrase = trim($userAvailabilityPhrase);

        if ('' === $userAvailabilityPhrase) {
            return false;
        }

        $userIsUnavailable = 1 === preg_match('/' . $userAvailabilityPhrase . ' (.+)/i', $message->content, $matches);

        if (!$userIsUnavailable || !isset($matches[1])) {
            return false;
        }

        /** Validate unavailability time */
        $userUnavailableTime = Bot::getTimeFromString($matches[1]);

        if (false === $userUnavailableTime || time() >= $userUnavailableTime) {
            return false;
        }

        /** Respond with a prompt */
        $actionRow = ActionRow::new()
        ->addComponent(
            Button::new(Button::STYLE_PRIMARY)
            ->setLabel('Yes')
            ->setListener(
                function (Interaction $interaction) use ($userUnavailableTime, $message) {
                    $this->setUserAvailability($interaction->user, false, $userUnavailableTime);

                    $interaction->message->delete();
                    $message->reply(
                        MessageBuilder::new()
                        ->setContent(
                            sprintf(
                                'Alrighty! You are now officially **unavailable** on `%s` at `%s`.',
                                date('d.m.Y', $userUnavailableTime),
                                date('H:i', $userUnavailableTime)
                            )
                        )
                        ->_setFlags(Message::FLAG_EPHEMERAL)
                    );
                },
                $discord
            )
        )
        ->addComponent(
            Button::new(Button::STYLE_SECONDARY)
            ->setLabel('No')
            ->setListener(
                function (Interaction $interaction) {
                    $interaction
                    ->respondWithMessage(
                        MessageBuilder::new()
                        ->setContent('Whoops, sorry!'),
                        true
                    );
                    $interaction->message->delete();
                },
                $discord
            )
        );

        $messageReply = MessageBuilder::new()
        ->setContent(
            sprintf(
                'You will be **unavailable** for dota on `%s` at `%s`, did I get that right?',
                date('d.m.Y', $userUnavailableTime),
                date('H:i', $userUnavailableTime),
            )
        )
        ->_setFlags(Message::FLAG_EPHEMERAL)
        ->addComponent($actionRow);

        $message->reply($messageReply);

        return true;
    }

    protected function getAvailabilities(): array
    {
        $availabilities = array();
        $directory      = $this->config->getAvailabilitiesDir();

        if (!is_dir($directory)) {
            return $availabilities;
        }

        $files = array_filter(
            scandir($directory),
            function ($filename) use ($directory) {
                $filepath = $directory . '/'  . $filename;

                return is_file($filepath);
            }
        );

        foreach ($files as $filename) {
            $filepath         = $directory . '/' . $filename;
            $fileContents     = file_get_contents($filepath);
            $availabilitiy    = json_decode($fileContents, true);
            $availabilities[] = $availabilitiy;
        }

        return $availabilities;
    }
}
