<?php
declare(strict_types=1);

// Installed from modules/contact-form (v2).
// Module strings are merged by load_i18n() after the site's own file.
return [
    'nav_contact'               => 'Contact',
    'contact_title'             => 'Contact',
    'contact_intro'             => 'Send a message and you will get a reply.',

    'contact_field_name'        => 'Your name',
    'contact_field_email'       => 'Your email',
    'contact_field_subject'     => 'Subject',
    'contact_field_message'     => 'Message',
    'contact_human_check'       => "I'm human",
    'contact_submit'            => 'Send message',

    'contact_sent'              => 'Thank you — your message has been sent.',
    'contact_error_name'        => 'Please enter your name (max 100 characters).',
    'contact_error_email'       => 'Please enter a valid email address.',
    'contact_error_subject'     => 'Please enter a subject (max 200 characters).',
    'contact_error_message'     => 'Please write a message.',
    'contact_error_message_long'=> 'Your message is too long (max 5000 characters).',
    'contact_error_send'        => 'The message could not be sent. Please email us directly instead.',
    'contact_error_rate_limited'=> 'Too many submissions from this connection. Please wait a while and try again.',
    'contact_js_required'       => 'This form needs JavaScript. Without it, email us at',

    // Shown on EVERY 404, not only a blocked one — that unconditionality is
    // the mechanism. A mistyped URL and a bot-blocked submission render the
    // same page, so a scanner learns nothing, while the wording means
    // something only to a person who was actually using the form.
    'notfound_form_hint'        => 'Were you sending us a message? If the form did not go through, email us directly — we would rather hear from you than lose the message.',
];
