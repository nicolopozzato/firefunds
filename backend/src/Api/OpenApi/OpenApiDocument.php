<?php

namespace App\Api\OpenApi;

use App\Draft\DTO\CreateDraftRequest;

/**
 * The OpenAPI description of what other programs (a phone, a script) do with
 * a personal access token: bring in drafts, transactions and the phone's
 * notifications, and read what fills them. Written by hand rather than read
 * off every controller: the app's own screens use many more routes, which a
 * `full` token may call too but which are not a promise to anybody.
 * `OpenApiDocumentTest` keeps every path here in step with the router.
 */
final class OpenApiDocument
{
    /** @return array<string, mixed> */
    public function build(string $serverUrl, string $version): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'FireFunds API',
                'version' => $version,
                'description' => "What a personal access token can do. Make a token in Settings → Connections and send it as `Authorization: Bearer ff_…`.\n\n"
                    ."**Scopes.** `drafts` may only send drafts and notifications and read accounts, categories, tags and the user's profile (the endpoints below marked for it). `full` may call every endpoint here and the rest of the app's API, but never tokens, administration, push devices or the password.\n\n"
                    ."**Amounts** are decimal strings (`\"12.50\"`), **dates** are written where the thing happened (`2026-10-28T12:00:00`, no offset) together with a `timezone` (IANA, e.g. `Europe/Rome`); the user's own is used when left out.\n\n"
                    .'**Errors** are JSON with a `code` (stable, for programs) and an English `message` (for logs).',
            ],
            'servers' => [['url' => $serverUrl]],
            'security' => [['bearerToken' => []]],
            'tags' => [
                ['name' => 'Drafts', 'description' => 'Something that may be a transaction, for the user to review in the app.'],
                ['name' => 'Transactions', 'description' => 'Money transactions (`full` token).'],
                ['name' => 'Notifications', 'description' => 'A phone notification as it was shown; the server reads it into a draft.'],
                ['name' => 'Lookups', 'description' => 'What the ids in the requests above come from.'],
            ],
            'paths' => [
                '/api/drafts' => ['post' => [
                    'tags' => ['Drafts'],
                    'operationId' => 'createDraft',
                    'summary' => 'Send a draft',
                    'description' => 'An amount and a moment at least. If an identical transaction is recorded already, `ifExists` says what to do. If a recurring transaction is expected, the draft is recorded as that occurrence on the spot (`status: matched`). Otherwise it waits in Transactions → To review and the user is notified. Scope: `drafts` or `full`.',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/CreateDraft'],
                        'example' => ['amount' => '12.50', 'occurredAt' => '2026-10-28T12:30:00', 'timezone' => 'Europe/Rome', 'description' => 'Bar Centrale', 'direction' => 'out', 'rawText' => 'Pagamento di 12,50 EUR presso Bar Centrale'],
                    ]]],
                    'responses' => [
                        '201' => ['description' => 'The draft: `pending` for the user, or already dealt with.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Draft']]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '403' => ['$ref' => '#/components/responses/Forbidden'],
                        '422' => ['$ref' => '#/components/responses/Invalid'],
                    ],
                ]],
                '/api/transactions' => ['post' => [
                    'tags' => ['Transactions'],
                    'operationId' => 'createTransaction',
                    'summary' => 'Record a transaction',
                    'description' => 'A withdrawal (from an asset account to an expense account or to nobody), a deposit (from a revenue account or nobody to an asset account) or a transfer between asset accounts. At least one side is required. A side may be given by id or, for expense and revenue accounts, by name (`newSourceAccountName`, `newDestinationAccountName`: the user\'s one with that name is used, else it is created). Same for the category and tags. Scope: `full`.',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/CreateTransaction'],
                        'example' => ['description' => 'Spesa', 'sourceAccountId' => '00000000-0000-0000-0000-000000000000', 'newDestinationAccountName' => 'Esselunga', 'amount' => '42.80', 'occurredAt' => '2026-10-28T18:05:00', 'ifExists' => 'skip'],
                    ]]],
                    'responses' => [
                        '201' => ['description' => 'The transaction was made.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Transaction']]]],
                        '200' => ['description' => 'With `ifExists` `skip` or `annotate`: the identical transaction already there.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Transaction']]]],
                        '202' => ['description' => 'With `ifExists` `review`: a draft for the user to decide.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Draft']]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '403' => ['$ref' => '#/components/responses/Forbidden'],
                        '422' => ['$ref' => '#/components/responses/Invalid'],
                    ],
                ]],
                '/api/bank-notifications' => ['post' => [
                    'tags' => ['Notifications'],
                    'operationId' => 'receiveNotification',
                    'summary' => 'Send a notification',
                    'description' => 'What the Android app sends. The server reads it with the rules of its source (Sources page in the app) and makes a draft of a payment. The same `key` sent again is kept once. Scope: `drafts` or `full`.',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/IncomingNotification'],
                    ]]],
                    'responses' => [
                        '201' => ['description' => 'What it became.', 'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string', 'format' => 'uuid'],
                                'status' => ['type' => 'string', 'description' => 'Whether it became a draft, was ignored by a rule, or was not understood (unread).'],
                                'draftId' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                            ],
                        ]]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '403' => ['$ref' => '#/components/responses/Forbidden'],
                        '422' => ['$ref' => '#/components/responses/Invalid'],
                    ],
                ]],
                '/api/accounts/{type}' => ['get' => [
                    'tags' => ['Lookups'],
                    'operationId' => 'listAccounts',
                    'summary' => 'List accounts',
                    'description' => 'The ids to use as `sourceAccountId` and `destinationAccountId`. Asset accounts are the user\'s own (bank, cash, broker); expense and revenue accounts are who was paid and who paid. Scope: `drafts` or `full`.',
                    'parameters' => [
                        ['name' => 'type', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['asset', 'expense', 'revenue']]],
                        ['name' => 'pageSize', 'in' => 'query', 'schema' => ['type' => 'integer'], 'description' => 'Up to 500; `-1` for all of them.'],
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'A page of accounts.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/AccountPage']]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '403' => ['$ref' => '#/components/responses/Forbidden'],
                    ],
                ]],
                '/api/categories' => ['get' => [
                    'tags' => ['Lookups'],
                    'operationId' => 'listCategories',
                    'summary' => 'List categories',
                    'description' => 'Scope: `drafts` or `full`.',
                    'parameters' => [
                        ['name' => 'pageSize', 'in' => 'query', 'schema' => ['type' => 'integer'], 'description' => 'Up to 500; `-1` for all of them.'],
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'A page of categories.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/NamePage']]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '403' => ['$ref' => '#/components/responses/Forbidden'],
                    ],
                ]],
                '/api/tags' => ['get' => [
                    'tags' => ['Lookups'],
                    'operationId' => 'listTags',
                    'summary' => 'List tags',
                    'description' => 'Scope: `drafts` or `full`.',
                    'parameters' => [
                        ['name' => 'pageSize', 'in' => 'query', 'schema' => ['type' => 'integer'], 'description' => 'Up to 500; `-1` for all of them.'],
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'A page of tags.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/NamePage']]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '403' => ['$ref' => '#/components/responses/Forbidden'],
                    ],
                ]],
                '/api/auth/me' => ['get' => [
                    'tags' => ['Lookups'],
                    'operationId' => 'whoAmI',
                    'summary' => 'The token\'s user',
                    'description' => 'Who the token acts as, with their timezone and preferences. A quick way to check that a token works. Scope: `drafts` or `full`.',
                    'responses' => [
                        '200' => ['description' => 'The user.', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => true]]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    ],
                ]],
            ],
            'components' => [
                'securitySchemes' => ['bearerToken' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'description' => 'A personal access token (`ff_` and 40 characters), made in Settings → Connections and shown once.',
                ]],
                'responses' => [
                    'Unauthorized' => ['description' => 'No token, an unknown or revoked one.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                    'Forbidden' => ['description' => 'The token\'s scope does not allow this (`code: api_token_scope`).', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                    'Invalid' => ['description' => 'A field is wrong; `violations` names each, with a `code`.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                ],
                'schemas' => [
                    'Error' => ['type' => 'object', 'properties' => [
                        'code' => ['type' => 'string'],
                        'message' => ['type' => 'string', 'description' => 'English, for logs: never shown as it is.'],
                        'status' => ['type' => 'integer'],
                        'violations' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'propertyPath' => ['type' => 'string', 'description' => 'The field, e.g. `amount`.'],
                            'code' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                        ]]],
                    ]],
                    'CreateDraft' => [
                        'type' => 'object',
                        'required' => ['amount', 'occurredAt'],
                        'properties' => [
                            'amount' => ['type' => 'string', 'description' => 'Positive decimal, e.g. "12.50".'],
                            'occurredAt' => ['type' => 'string', 'description' => 'When it happened, written where it happened: 2026-10-28T12:30:00 (no offset).'],
                            'timezone' => ['type' => 'string', 'description' => 'IANA zone of `occurredAt`; the user\'s own when left out.'],
                            'description' => ['type' => 'string', 'maxLength' => 255],
                            'direction' => ['type' => 'string', 'enum' => ['out', 'in'], 'description' => 'Spent or received; left out when unknown.'],
                            'sourceAccountId' => ['type' => 'string', 'format' => 'uuid'],
                            'destinationAccountId' => ['type' => 'string', 'format' => 'uuid'],
                            'notes' => ['type' => 'string', 'maxLength' => 10000],
                            'rawText' => ['type' => 'string', 'maxLength' => 4000, 'description' => 'What arrived as it arrived, e.g. a notification\'s text.'],
                            'source' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]{1,19}$', 'description' => 'Who sends it, e.g. "shortcuts"; "api" when left out.'],
                            'sourceName' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Which one, e.g. the bank\'s app. The token\'s name when left out.'],
                            'ifExists' => ['$ref' => '#/components/schemas/IfExists', 'default' => 'review'],
                        ],
                    ],
                    'IfExists' => [
                        'type' => 'string',
                        'enum' => CreateDraftRequest::IF_EXISTS,
                        'description' => 'What to do when an identical transaction is there already (same account when given, same amount to the cent, within a day): `review` asks the user, `skip` leaves it, `annotate` adds this text to its notes, `create` makes it anyway.',
                    ],
                    'Draft' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'],
                        'status' => ['type' => 'string', 'description' => '`pending` (waits for the user), or already dealt with (`matched`, recorded, discarded).'],
                        'source' => ['type' => 'string'],
                        'sourceName' => ['type' => ['string', 'null']],
                        'description' => ['type' => ['string', 'null']],
                        'amount' => ['type' => 'string'],
                        'currency' => ['type' => 'string'],
                        'direction' => ['type' => ['string', 'null'], 'enum' => ['out', 'in', null]],
                        'occurredAt' => ['type' => 'string'],
                        'timezone' => ['type' => 'string'],
                        'sourceAccount' => ['type' => ['object', 'null'], 'additionalProperties' => true],
                        'destinationAccount' => ['type' => ['object', 'null'], 'additionalProperties' => true],
                        'notes' => ['type' => ['string', 'null']],
                        'rawText' => ['type' => ['string', 'null']],
                        'possibleDuplicate' => ['type' => ['object', 'null'], 'additionalProperties' => true],
                        'transactionIds' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']],
                        'createdAt' => ['type' => 'string'],
                    ]],
                    'CreateTransaction' => [
                        'type' => 'object',
                        'required' => ['description', 'amount', 'occurredAt'],
                        'properties' => [
                            'description' => ['type' => 'string', 'maxLength' => 255],
                            'amount' => ['type' => 'string', 'description' => 'Positive decimal.'],
                            'occurredAt' => ['type' => 'string', 'description' => 'Written where it happened, no offset.'],
                            'timezone' => ['type' => 'string'],
                            'sourceAccountId' => ['type' => ['string', 'null'], 'format' => 'uuid', 'description' => 'Null for money from nobody in particular.'],
                            'destinationAccountId' => ['type' => ['string', 'null'], 'format' => 'uuid', 'description' => 'Null for money handed out. Never both null.'],
                            'newSourceAccountName' => ['type' => 'string', 'description' => 'A revenue account by name, in place of `sourceAccountId`.'],
                            'newDestinationAccountName' => ['type' => 'string', 'description' => 'An expense account by name, in place of `destinationAccountId`.'],
                            'destinationAmount' => ['type' => 'string', 'description' => 'A transfer between accounts in different currencies: what arrived.'],
                            'foreignAmount' => ['type' => 'string'],
                            'foreignCurrency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                            'categoryId' => ['type' => 'string', 'format' => 'uuid'],
                            'newCategoryName' => ['type' => 'string'],
                            'tagIds' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']],
                            'newTagNames' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'notes' => ['type' => 'string', 'maxLength' => 10000],
                            'ifExists' => ['$ref' => '#/components/schemas/IfExists'],
                        ],
                    ],
                    'Transaction' => ['type' => 'object', 'additionalProperties' => true, 'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'],
                        'description' => ['type' => 'string'],
                        'amount' => ['type' => 'string'],
                        'occurredAt' => ['type' => 'string'],
                    ]],
                    'IncomingNotification' => [
                        'type' => 'object',
                        'required' => ['package', 'appName', 'postedAt', 'timezone', 'key'],
                        'properties' => [
                            'package' => ['type' => 'string', 'maxLength' => 255, 'description' => 'The app\'s identifier, e.g. com.bank.app.'],
                            'appName' => ['type' => 'string', 'maxLength' => 120],
                            'title' => ['type' => 'string', 'maxLength' => 1000],
                            'text' => ['type' => 'string', 'maxLength' => 4000],
                            'postedAt' => ['type' => 'string', 'description' => 'When it was shown, written in `timezone`.'],
                            'timezone' => ['type' => 'string'],
                            'key' => ['type' => 'string', 'maxLength' => 255, 'description' => 'The sender\'s own id of it: the same one sent again is kept once.'],
                        ],
                    ],
                    'AccountPage' => ['type' => 'object', 'properties' => [
                        'items' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true, 'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'type' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'isActive' => ['type' => 'boolean'],
                            'currencyCode' => ['type' => ['string', 'null']],
                        ]]],
                        'total' => ['type' => 'integer'], 'page' => ['type' => 'integer'], 'pageSize' => ['type' => 'integer'],
                    ]],
                    'NamePage' => ['type' => 'object', 'properties' => [
                        'items' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true, 'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'name' => ['type' => 'string'],
                        ]]],
                        'total' => ['type' => 'integer'], 'page' => ['type' => 'integer'], 'pageSize' => ['type' => 'integer'],
                    ]],
                ],
            ],
        ];
    }
}
