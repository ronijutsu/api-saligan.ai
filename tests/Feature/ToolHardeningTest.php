<?php

use App\Ai\Tools\CreateTodoTool;
use App\Models\Conversation;
use App\Models\Todo;
use App\Models\User;
use Laravel\Ai\Tools\Request;

/**
 * A tool schema is a request, not a contract. Providers deliver arguments the
 * schema says are impossible, and every one of these cases used to end the
 * turn — mid-stream, after the user had already read half an answer.
 */
beforeEach(function () {
    $this->conversation = Conversation::factory()->for(User::factory())->create();
});

function todoTool(): CreateTodoTool
{
    return new CreateTodoTool(test()->conversation->id);
}

it('survives an item with no title instead of taking the turn down', function () {
    $result = json_decode(todoTool()->handle(new Request([
        'items' => [
            ['status' => 'pending'],
            ['title' => 'File the complaint with the RTC'],
        ],
    ])), true);

    expect($result['accepted'])->toBe(1)
        ->and($result['rejected_count'])->toBe(1)
        ->and(Todo::query()->count())->toBe(1);
});

it('normalizes a status the database enum would have rejected', function () {
    // "in progress" is what a model writes when the listed value is "on-going".
    // Postgres answers an unlisted value with a constraint violation, which
    // ends the request rather than the item.
    todoTool()->handle(new Request([
        'items' => [['title' => 'Serve the demand letter', 'status' => 'in progress', 'priority' => 'URGENT']],
    ]));

    $todo = Todo::query()->sole();

    expect($todo->status)->toBe('pending')
        ->and($todo->priority)->toBeNull();
});

it('accepts the separator variants a model writes an enum with', function () {
    todoTool()->handle(new Request([
        'items' => [['title' => 'Pay the filing fees', 'status' => 'on going', 'priority' => 'High']],
    ]));

    $todo = Todo::query()->sole();

    expect($todo->status)->toBe('on-going')
        ->and($todo->priority)->toBe('high');
});

it('strips the checklist markup a model carries over from the text block', function () {
    todoTool()->handle(new Request([
        'items' => [
            ['title' => '- [ ] Have the deed notarized'],
            ['title' => '**2. Register with the Register of Deeds**'],
        ],
    ]));

    expect(Todo::query()->orderBy('created_at')->pluck('title')->all())
        ->toBe(['Have the deed notarized', 'Register with the Register of Deeds']);
});

it('does not file a task this thread already has', function () {
    Todo::create(['conversation_id' => $this->conversation->id, 'title' => 'Pay the filing fees']);

    $result = json_decode(todoTool()->handle(new Request([
        'items' => [['title' => 'Pay the filing fees.'], ['title' => 'Serve the notice']],
    ])), true);

    expect($result['accepted'])->toBe(1)
        ->and(Todo::query()->count())->toBe(2);
});

it('serves a retried tool call from the first attempt rather than filing twice', function () {
    $tool = todoTool();
    $arguments = ['items' => [['title' => 'File the answer']]];

    $first = $tool->handle(new Request($arguments, 'call-1'));
    $second = $tool->handle(new Request($arguments, 'call-1'));

    expect($second)->toBe($first)
        ->and(Todo::query()->count())->toBe(1);
});

it('caps a runaway list rather than writing every row a model produced', function () {
    $items = [];

    for ($i = 0; $i < 60; $i++) {
        $items[] = ['title' => 'Step number '.$i];
    }

    todoTool()->handle(new Request(['items' => $items]));

    expect(Todo::query()->count())->toBe(25);
});

it('tells the model plainly when nothing was filed', function () {
    $result = todoTool()->handle(new Request(['items' => 'File the complaint']));

    expect(json_decode($result, true)['accepted'])->toBe(0)
        ->and($result)->toContain('Do not tell the user you added anything');
});

it('unwraps a single item a provider sent without its list', function () {
    // `{"title": "..."}` where `[{"title": "..."}]` was declared.
    todoTool()->handle(new Request(['items' => ['title' => 'Notarize the affidavit']]));

    expect(Todo::query()->sole()->title)->toBe('Notarize the affidavit');
});
