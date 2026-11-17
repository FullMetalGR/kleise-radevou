<?php

/*
 * The help panel in English: the words the panel reads, sent with it only when it opens, in the
 * language of the request, the user's. Its Greek twin is lang/el/admin/help.php. The bubble's own
 * two lines are not here but in the shell's help group, which every screen carries, and the
 * panel's heading is the first of them; the answers are lang/en/admin/help_answers.php.
 *
 * platform is the name a platform message is written under on the business's side, :brand the
 * product's name. close is the panel's close button. tabs is its two tabs, the second's name with
 * the unread state said in words beside it. search is the box over the answers, the line saying
 * how many answers match, read as a choice by the count (:count), and the line when none does;
 * still is the line at the foot of the answers, open the button that opens the form from there,
 * from the list and from a search with no match, and back the button that leaves the form or a
 * thread for the list. retry is the button beside a list or a thread that could not be loaded.
 *
 * form is the new ticket's form: its heading, its two fields and its send button with its busy
 * word. list is the Tickets tab's list: its busy line, its line with no ticket, a row's unread
 * mark said in words, a row's time of its last message (:time) and its line when it could not be
 * loaded. statuses is a ticket's two statuses. thread is one ticket: its busy line, the line under
 * a closed one, the reply box's label, its send button with its busy word, and its line when it
 * could not be loaded.
 *
 * validation is the form's two refusals, a subject and a message each read as a choice by its
 * most characters (:count), which the panel shows for an empty field too, as the server does.
 * refusals is the server's answer at each hourly limit, read as a choice by the limit (:count),
 * and its answer to the demo account, which has no tickets.
 */

return [
    'platform' => ':brand Support',
    'close' => 'Close',
    'tabs' => [
        'answers' => 'Answers',
        'tickets' => 'Tickets',
        'tickets_unread' => 'Tickets, a ticket has a new reply',
    ],
    'search' => [
        'label' => 'Search the answers',
        'found' => '{1} :count answer matches.|[2,*] :count answers match.',
        'none' => 'No answer matches your search.',
    ],
    'still' => 'Still need help?',
    'open' => 'Open a ticket',
    'back' => 'All tickets',
    'retry' => 'Try again',
    'form' => [
        'title' => 'New ticket',
        'subject' => 'Subject',
        'body' => 'Message',
        'send' => 'Send',
        'sending' => 'Sending...',
    ],
    'list' => [
        'loading' => 'Loading the tickets',
        'empty' => 'No tickets yet.',
        'unread' => 'New reply',
        'last' => 'Last message :time',
        'failed' => 'The tickets could not be loaded.',
    ],
    'statuses' => [
        'open' => 'Open',
        'closed' => 'Closed',
    ],
    'thread' => [
        'loading' => 'Loading the ticket',
        'closed' => 'This ticket is closed. A reply reopens it.',
        'reply' => 'Your reply',
        'send' => 'Send reply',
        'sending' => 'Sending...',
        'failed' => 'This ticket could not be loaded.',
    ],
    'validation' => [
        'subject' => '{1} Write a subject of at most :count character.|[2,*] Write a subject of at most :count characters.',
        'body' => '{1} Write a message of at most :count character.|[2,*] Write a message of at most :count characters.',
    ],
    'refusals' => [
        'tickets' => '{1} You can open at most :count ticket an hour. Please try again later.|[2,*] You can open at most :count tickets an hour. Please try again later.',
        'messages' => '{1} You can send at most :count message an hour. Please try again later.|[2,*] You can send at most :count messages an hour. Please try again later.',
        'demo' => 'Tickets are not available on the demo account.',
    ],
];
