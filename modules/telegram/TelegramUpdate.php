<?php
/**
 * Normalized view over a Telegram Update object.
 *
 * Telegram keeps adding update types; this wrapper keeps the module code free
 * of deep array access and provides one place with type names and icons.
 *
 * @package MajorDoMo
 */

class TelegramUpdate
{
    /** @var array */
    private $data = array();
    /** @var string */
    private $type = '';
    /** @var array */
    private $message = array();
    /** @var array */
    private $from = array();
    /** @var array */
    private $chat = array();

    /**
     * @param array $data Decoded update object.
     */
    public function __construct(array $data)
    {
        $this->data = $data;
        $this->type = self::detectType($data);

        if (isset($data['business_message']) && is_array($data['business_message'])) {
            $this->message = $data['business_message'];
        } elseif (isset($data['edited_business_message']) && is_array($data['edited_business_message'])) {
            $this->message = $data['edited_business_message'];
        } elseif (isset($data['edited_message']) && is_array($data['edited_message'])) {
            $this->message = $data['edited_message'];
        } elseif (isset($data['channel_post']) && is_array($data['channel_post'])) {
            $this->message = $data['channel_post'];
        } elseif (isset($data['edited_channel_post']) && is_array($data['edited_channel_post'])) {
            $this->message = $data['edited_channel_post'];
        } elseif (isset($data['message']) && is_array($data['message'])) {
            $this->message = $data['message'];
        } elseif (isset($data['callback_query']['message']) && is_array($data['callback_query']['message'])) {
            $this->message = $data['callback_query']['message'];
        } elseif (isset($data['poll_answer']) && is_array($data['poll_answer'])) {
            $this->message = array();
        }

        if (isset($data['callback_query']['from']) && is_array($data['callback_query']['from'])) {
            $this->from = $data['callback_query']['from'];
        } elseif (isset($this->message['from']) && is_array($this->message['from'])) {
            $this->from = $this->message['from'];
        } elseif (isset($data['inline_query']['from']) && is_array($data['inline_query']['from'])) {
            $this->from = $data['inline_query']['from'];
        } elseif (isset($data['poll_answer']['user']) && is_array($data['poll_answer']['user'])) {
            $this->from = $data['poll_answer']['user'];
        } elseif (isset($data['chat_join_request']['from']) && is_array($data['chat_join_request']['from'])) {
            $this->from = $data['chat_join_request']['from'];
        } elseif (isset($data['chat_member']['from']) && is_array($data['chat_member']['from'])) {
            $this->from = $data['chat_member']['from'];
        } elseif (isset($data['my_chat_member']['from']) && is_array($data['my_chat_member']['from'])) {
            $this->from = $data['my_chat_member']['from'];
        } elseif (isset($data['message_reaction']['user']) && is_array($data['message_reaction']['user'])) {
            $this->from = $data['message_reaction']['user'];
        }

        if (isset($this->message['chat']) && is_array($this->message['chat'])) {
            $this->chat = $this->message['chat'];
        } elseif (isset($data['callback_query']['message']['chat']) && is_array($data['callback_query']['message']['chat'])) {
            $this->chat = $data['callback_query']['message']['chat'];
        } elseif (isset($data['chat_join_request']['chat']) && is_array($data['chat_join_request']['chat'])) {
            $this->chat = $data['chat_join_request']['chat'];
        } elseif (isset($data['chat_member']['chat']) && is_array($data['chat_member']['chat'])) {
            $this->chat = $data['chat_member']['chat'];
        } elseif (isset($data['my_chat_member']['chat']) && is_array($data['my_chat_member']['chat'])) {
            $this->chat = $data['my_chat_member']['chat'];
        } elseif (isset($data['message_reaction']['chat']) && is_array($data['message_reaction']['chat'])) {
            $this->chat = $data['message_reaction']['chat'];
        } elseif (isset($data['business_message']['chat']) && is_array($data['business_message']['chat'])) {
            $this->chat = $data['business_message']['chat'];
        }
    }

    /**
     * @param array $data
     * @return TelegramUpdate
     */
    public static function fromArray(array $data)
    {
        return new self($data);
    }

    /**
     * @param array $data
     * @return string One of the keys below.
     */
    public static function detectType(array $data)
    {
        $priority = array(
            'message',
            'edited_message',
            'channel_post',
            'edited_channel_post',
            'business_message',
            'edited_business_message',
            'callback_query',
            'inline_query',
            'chosen_inline_result',
            'poll',
            'poll_answer',
            'chat_member',
            'my_chat_member',
            'chat_join_request',
            'message_reaction',
            'message_reaction_count',
            'deleted_business_messages',
            'pre_checkout_query',
            'shipping_query',
            'successful_payment',
        );
        foreach ($priority as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $key;
            }
        }
        return 'unknown';
    }

    /**
     * Stable numeric type used by the `tlg_event.TYPE_EVENT` field.
     *
     * 1 text, 2 photo, 3 voice, 4 audio, 5 video, 6 document, 7 sticker,
     * 8 location, 9 callback, 10 poll answer, 11 video note, 12 contact,
     * 13 venue, 14 dice, 15 animation, 16 edited message, 17 channel post,
     * 18 chat member/service, 19 reaction, 20 inline query, 21 business message.
     *
     * @return int
     */
    public function eventType()
    {
        switch ($this->type) {
            case 'callback_query':
                return 9;
            case 'poll_answer':
                return 10;
            case 'inline_query':
            case 'chosen_inline_result':
                return 20;
            case 'edited_message':
            case 'edited_business_message':
            case 'edited_channel_post':
                return 16;
            case 'channel_post':
                return 17;
            case 'chat_member':
            case 'my_chat_member':
            case 'chat_join_request':
                return 18;
            case 'message_reaction':
            case 'message_reaction_count':
                return 19;
            case 'business_message':
            case 'deleted_business_messages':
                return 21;
            case 'pre_checkout_query':
            case 'shipping_query':
            case 'successful_payment':
                return 22;
        }

        $media = $this->media();
        return $media['event_type'];
    }

    /**
     * Rich media descriptor for the current message.
     *
     * @return array{kind:string,event_type:int,file_id:string,file_name:string,file_size:int}
     */
    public function media()
    {
        $m = $this->message;
        $result = array('kind' => '', 'event_type' => 1, 'file_id' => '', 'file_name' => '', 'file_size' => 0);
        if (isset($m['photo']) && is_array($m['photo']) && $m['photo']) {
            $sizes = $m['photo'];
            $last = end($sizes);
            $result = array(
                'kind' => 'photo',
                'event_type' => 2,
                'file_id' => isset($last['file_id']) ? (string)$last['file_id'] : '',
                'file_name' => '',
                'file_size' => isset($last['file_size']) ? (int)$last['file_size'] : 0,
            );
        } elseif (isset($m['voice'])) {
            $result = $this->fileDescriptor('voice', 3, $m['voice']);
        } elseif (isset($m['audio'])) {
            $result = $this->fileDescriptor('audio', 4, $m['audio']);
        } elseif (isset($m['video_note'])) {
            $result = $this->fileDescriptor('video_note', 11, $m['video_note']);
        } elseif (isset($m['video'])) {
            $result = $this->fileDescriptor('video', 5, $m['video']);
        } elseif (isset($m['animation'])) {
            $result = $this->fileDescriptor('animation', 15, $m['animation']);
        } elseif (isset($m['document'])) {
            $result = $this->fileDescriptor('document', 6, $m['document']);
        } elseif (isset($m['sticker'])) {
            $result = $this->fileDescriptor('sticker', 7, $m['sticker']);
        } elseif (isset($m['location'])) {
            $result = array('kind' => 'location', 'event_type' => 8, 'file_id' => '', 'file_name' => '', 'file_size' => 0);
        } elseif (isset($m['venue'])) {
            $result = array('kind' => 'venue', 'event_type' => 13, 'file_id' => '', 'file_name' => '', 'file_size' => 0);
        } elseif (isset($m['contact'])) {
            $result = array('kind' => 'contact', 'event_type' => 12, 'file_id' => '', 'file_name' => '', 'file_size' => 0);
        } elseif (isset($m['dice'])) {
            $result = array('kind' => 'dice', 'event_type' => 14, 'file_id' => '', 'file_name' => '', 'file_size' => 0);
        }
        return $result;
    }

    /**
     * @param string $kind
     * @param int    $eventType
     * @param array  $payload
     * @return array
     */
    private function fileDescriptor($kind, $eventType, $payload)
    {
        $payload = is_array($payload) ? $payload : array();
        $name = '';
        if (isset($payload['file_name'])) {
            $name = (string)$payload['file_name'];
        } elseif (isset($payload['title'])) {
            $name = (string)$payload['title'];
        }
        return array(
            'kind' => $kind,
            'event_type' => $eventType,
            'file_id' => isset($payload['file_id']) ? (string)$payload['file_id'] : '',
            'file_name' => $name,
            'file_size' => isset($payload['file_size']) ? (int)$payload['file_size'] : 0,
        );
    }

    /**
     * @return array
     */
    public function raw()
    {
        return $this->data;
    }

    /**
     * @return string
     */
    public function type()
    {
        return $this->type;
    }

    /**
     * @return int
     */
    public function updateId()
    {
        return isset($this->data['update_id']) ? (int)$this->data['update_id'] : 0;
    }

    /**
     * @return array
     */
    public function message()
    {
        return $this->message;
    }

    /**
     * @return array
     */
    public function from()
    {
        return $this->from;
    }

    /**
     * @return array
     */
    public function chat()
    {
        return $this->chat;
    }

    /**
     * @return mixed Chat id (int for private chats, negative for groups).
     */
    public function chatId()
    {
        if (isset($this->chat['id'])) {
            return $this->chat['id'];
        }
        if (isset($this->data['callback_query']['from']['id'])) {
            return $this->data['callback_query']['from']['id'];
        }
        if (isset($this->data['inline_query']['from']['id'])) {
            return $this->data['inline_query']['from']['id'];
        }
        if (isset($this->data['poll_answer']['user']['id'])) {
            return $this->data['poll_answer']['user']['id'];
        }
        return 0;
    }

    /**
     * @return int
     */
    public function fromId()
    {
        return isset($this->from['id']) ? (int)$this->from['id'] : 0;
    }

    /**
     * @return string
     */
    public function username()
    {
        return isset($this->from['username']) ? (string)$this->from['username'] : '';
    }

    /**
     * @return string
     */
    public function firstName()
    {
        return isset($this->from['first_name']) ? (string)$this->from['first_name'] : '';
    }

    /**
     * @return string
     */
    public function lastName()
    {
        return isset($this->from['last_name']) ? (string)$this->from['last_name'] : '';
    }

    /**
     * @return string
     */
    public function fullName()
    {
        $name = trim($this->firstName() . ' ' . $this->lastName());
        return $name !== '' ? $name : $this->username();
    }

    /**
     * @return string
     */
    public function languageCode()
    {
        return isset($this->from['language_code']) ? (string)$this->from['language_code'] : '';
    }

    /**
     * @return bool
     */
    public function isPremium()
    {
        return !empty($this->from['is_premium']);
    }

    /**
     * @return string
     */
    public function text()
    {
        if ($this->type === 'callback_query') {
            return isset($this->data['callback_query']['data']) ? (string)$this->data['callback_query']['data'] : '';
        }
        if ($this->type === 'inline_query') {
            return isset($this->data['inline_query']['query']) ? (string)$this->data['inline_query']['query'] : '';
        }
        if (isset($this->message['text'])) {
            return (string)$this->message['text'];
        }
        return '';
    }

    /**
     * @return string
     */
    public function caption()
    {
        return isset($this->message['caption']) ? (string)$this->message['caption'] : '';
    }

    /**
     * Text or caption, whichever exists.
     *
     * @return string
     */
    public function content()
    {
        $text = $this->text();
        return $text !== '' ? $text : $this->caption();
    }

    /**
     * @return int
     */
    public function messageId()
    {
        return isset($this->message['message_id']) ? (int)$this->message['message_id'] : 0;
    }

    /**
     * @return int
     */
    public function messageThreadId()
    {
        return isset($this->message['message_thread_id']) ? (int)$this->message['message_thread_id'] : 0;
    }

    /**
     * @return string
     */
    public function callbackId()
    {
        return isset($this->data['callback_query']['id']) ? (string)$this->data['callback_query']['id'] : '';
    }

    /**
     * @return string
     */
    public function callbackData()
    {
        return isset($this->data['callback_query']['data']) ? (string)$this->data['callback_query']['data'] : '';
    }

    /**
     * @return bool
     */
    public function isGroup()
    {
        $type = isset($this->chat['type']) ? (string)$this->chat['type'] : '';
        return $type === 'group' || $type === 'supergroup';
    }

    /**
     * @return bool
     */
    public function isChannel()
    {
        return isset($this->chat['type']) && (string)$this->chat['type'] === 'channel';
    }

    /**
     * @return string
     */
    public function chatTitle()
    {
        if (isset($this->chat['title'])) {
            return (string)$this->chat['title'];
        }
        return $this->fullName();
    }

    /**
     * @return string
     */
    public function chatType()
    {
        return isset($this->chat['type']) ? (string)$this->chat['type'] : 'private';
    }

    /**
     * @return string
     */
    public function chatUsername()
    {
        return isset($this->chat['username']) ? (string)$this->chat['username'] : '';
    }

    /**
     * @return bool
     */
    public function isBotCommand()
    {
        $text = $this->text();
        return $text !== '' && substr($text, 0, 1) === '/';
    }

    /**
     * Return "/command args" split into [command, args].
     *
     * @return array{0:string,1:string}
     */
    public function commandParts()
    {
        $text = trim($this->text());
        if ($text === '' || $text[0] !== '/') {
            return array('', '');
        }
        $text = ltrim($text, '/');
        $parts = preg_split('/\s+/u', $text, 2);
        $command = isset($parts[0]) ? $parts[0] : '';
        $args = isset($parts[1]) ? trim($parts[1]) : '';
        if (strpos($command, '@') !== false) {
            list($command) = explode('@', $command, 2);
        }
        return array(mb_strtolower($command, 'UTF-8'), $args);
    }

    /**
     * Short message for the history list.
     *
     * @return string
     */
    public function summary()
    {
        switch ($this->type) {
            case 'callback_query':
                return 'callback: ' . (string)$this->data['callback_query']['data'];
            case 'poll_answer':
                $pollId = isset($this->data['poll_answer']['poll_id']) ? $this->data['poll_answer']['poll_id'] : '';
                $ids = isset($this->data['poll_answer']['option_ids']) ? implode(',', (array)$this->data['poll_answer']['option_ids']) : '';
                return 'poll ' . $pollId . ' / options: ' . $ids;
            case 'message_reaction':
            case 'message_reaction_count':
                $reactions = isset($this->data[$this->type]['reactions']) ? $this->data[$this->type]['reactions'] : array();
                $labels = array();
                foreach ((array)$reactions as $reaction) {
                    if (isset($reaction['emoji'])) {
                        $labels[] = $reaction['emoji'];
                    } elseif (isset($reaction['custom_emoji_id'])) {
                        $labels[] = 'custom:' . $reaction['custom_emoji_id'];
                    }
                }
                return 'reaction ' . implode(' ', $labels);
            case 'chat_join_request':
                return 'join request';
            case 'chat_member':
            case 'my_chat_member':
                $status = isset($this->data[$this->type]['new_chat_member']['status']) ? $this->data[$this->type]['new_chat_member']['status'] : '';
                return 'member status: ' . $status;
            case 'inline_query':
                return 'inline query: ' . $this->text();
        }

        $text = $this->content();
        if ($text !== '') {
            return $text;
        }

        $media = $this->media();
        if ($media['kind'] !== '') {
            return '[' . $media['kind'] . ']' . ($media['file_name'] !== '' ? ' ' . $media['file_name'] : '');
        }

        $service = $this->serviceSummary();
        if ($service !== '') {
            return $service;
        }

        return '[' . $this->type . ']';
    }

    /**
     * Summaries for service messages.
     *
     * @return string
     */
    public function serviceSummary()
    {
        $m = $this->message;
        $map = array(
            'new_chat_members' => 'new chat members',
            'left_chat_member' => 'left chat member',
            'new_chat_title' => 'new chat title',
            'new_chat_photo' => 'new chat photo',
            'delete_chat_photo' => 'chat photo deleted',
            'group_chat_created' => 'group created',
            'supergroup_chat_created' => 'supergroup created',
            'channel_chat_created' => 'channel created',
            'pinned_message' => 'pinned message',
            'forum_topic_created' => 'forum topic created',
            'forum_topic_closed' => 'forum topic closed',
            'forum_topic_reopened' => 'forum topic reopened',
            'video_chat_started' => 'video chat started',
            'video_chat_ended' => 'video chat ended',
            'proximity_alert_triggered' => 'proximity alert',
            'migrate_to_chat_id' => 'chat migrated',
            'message_auto_delete_timer_changed' => 'auto-delete timer changed',
        );
        foreach ($map as $key => $label) {
            if (isset($m[$key])) {
                return $label;
            }
        }
        return '';
    }

    /**
     * @return bool
     */
    public function isServiceMessage()
    {
        return $this->serviceSummary() !== '';
    }

    /**
     * File id of the current media (empty for text/service messages).
     *
     * @return string
     */
    public function fileId()
    {
        $media = $this->media();
        return $media['file_id'];
    }

    /**
     * Alias used by the old module API.
     *
     * @return string
     */
    public function PhotoIdBigSize()
    {
        $media = $this->media();
        return $media['kind'] === 'photo' ? $media['file_id'] : '';
    }
}
