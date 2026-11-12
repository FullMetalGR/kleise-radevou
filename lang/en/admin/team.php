<?php

/*
 * The Team screen: the people who sign in to this business's admin. page holds the title and the
 * count, read as a choice by the number of users; invite the invitation card, its fields, the
 * three roles' names and the sentence beneath; members the table, its chips, the role control's
 * name (:name the member's), the two actions and the removal's confirm; wait the sentence that
 * holds every control while a write is in flight; errors the four writes' fallbacks where the
 * server worded nothing.
 *
 * validation, refusals and flashes are the server's: the invitation's and the role's sentences,
 * the two guards' (the last owner and a removal of oneself), and the four writes' flashes, :email
 * the address a link went to and :name the member's name; the role's refusal names no role, and
 * invited and link_sent are read as choices by the link's hours, :count PasswordLinks::LIFETIME's
 * hours as digits. Its Greek twin is lang/el/admin/team.php.
 */

return [
    'page' => [
        'title' => 'Team',
        'count' => '{1} :count on the team|[2,*] :count on the team',
    ],
    'invite' => [
        'title' => 'Invite someone',
        'name' => 'Name',
        'email' => 'Email',
        'role' => 'Role',
        'send' => 'Send invitation',
        'sending' => 'Sending...',
        'roles_help' => 'An owner runs everything, billing included. A manager runs the calendar, the catalog and the settings. A staff member sees their own calendar and decides the requests on it. They receive a link to set their password.',
    ],
    'roles' => [
        'owner' => 'Owner',
        'manager' => 'Manager',
        'staff' => 'Staff',
    ],
    'members' => [
        'title' => 'Members',
        'name' => 'Name',
        'email' => 'Email',
        'staff_row' => 'Staff row',
        'role' => 'Role',
        'actions' => 'Actions',
        'you' => 'You',
        'invited' => 'Invitation out',
        'no_staff' => 'None',
        'role_of' => 'Role of :name',
        'link' => 'Send sign-in link',
        'sending' => 'Sending...',
        'remove' => 'Remove',
        'remove_self' => 'You cannot remove yourself. Another owner can.',
        'remove_title' => 'Remove :name?',
        'remove_body' => ':name can no longer sign in to this business. A staff row linked to them stays, with its hours and its bookings.',
        'removing' => 'Removing...',
        'remove_pending' => 'Please wait until :name is removed.',
    ],
    'wait' => 'Please wait until the change in progress is saved.',
    'errors' => [
        'invite' => 'The invitation could not be sent. Check your connection and try again.',
        'role' => 'The role could not be changed. Check your connection and try again.',
        'link' => 'The link could not be sent. Check your connection and try again.',
        'remove' => 'The member could not be removed. Check your connection and try again.',
    ],
    'validation' => [
        'name' => 'Enter the name of the person you are inviting.',
        'email' => 'Enter a valid email address.',
        'email_taken' => 'Someone on the team already signs in with this address.',
        'role' => 'Choose one of the roles offered.',
    ],
    'refusals' => [
        'last_owner' => 'The business needs at least one owner. Make someone else an owner first.',
        'self' => 'You cannot remove yourself from the team. Another owner can.',
    ],
    'flashes' => [
        'invited' => '{1} An invitation is on its way to :email. Its link works once, within :count hour.|[2,*] An invitation is on its way to :email. Its link works once, within :count hours.',
        'role_saved' => 'The role of :name is saved.',
        'link_sent' => '{1} A sign-in link is on its way to :email. It works once, within :count hour.|[2,*] A sign-in link is on its way to :email. It works once, within :count hours.',
        'removed' => ':name is no longer on the team.',
    ],
];
