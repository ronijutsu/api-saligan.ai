<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * One step of the trial welcome sequence (see App\Services\Lifecycle\WelcomeSequence).
 * The copy for all five steps lives here so the view stays a plain layout.
 */
class WelcomeSequenceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly int $step,
        public readonly ?int $trialDaysRemaining = null,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->copy()['subject']);
    }

    /**
     * RFC 8058 one-click unsubscribe, which Gmail and Yahoo expect from bulk
     * senders; the same signed URL is linked in the footer.
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $copy = $this->copy();

        return new Content(
            view: 'emails.welcome-sequence',
            with: [
                ...$copy,
                'firstName' => $this->firstName(),
                'ctaUrl' => $this->appUrl($copy['cta_path']),
                'secondaryUrl' => isset($copy['secondary_path']) ? $this->appUrl($copy['secondary_path']) : null,
                'signature' => config('saligan.welcome_sequence.signature'),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ],
        );
    }

    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('email.unsubscribe', ['user' => $this->user->id]);
    }

    private function firstName(): string
    {
        $first = strtok(trim((string) $this->user->name), ' ');

        return $first === false || $first === '' ? 'there' : $first;
    }

    /**
     * Links carry UTM tags so GA4 shows which email brought the user back.
     */
    private function appUrl(string $path): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $query = http_build_query([
            'utm_source' => 'email',
            'utm_medium' => 'lifecycle',
            'utm_campaign' => 'welcome',
            'utm_content' => "e{$this->step}",
        ]);

        return "{$base}{$path}?{$query}";
    }

    /**
     * @return array{subject: string, preview: string, heading: string, paragraphs: list<string>, bullets: list<string>, quote: ?string, after: ?string, cta_label: string, cta_path: string, secondary_label?: string, secondary_path?: string, postscript: ?string}
     */
    private function copy(): array
    {
        $daysLeft = $this->trialDaysRemaining;

        return match ($this->step) {
            1 => [
                'subject' => 'Welcome to Batayan — start with one question',
                'preview' => 'The fastest way to see what it can do takes 30 seconds.',
                'heading' => 'Welcome to Batayan',
                'paragraphs' => [
                    'Your 14-day trial is active and you don\'t need a card.',
                    'The quickest way to see if Batayan is worth your time: ask it the legal question that\'s been sitting at the back of your mind. For example:',
                ],
                'bullets' => [
                    '"Can I end a probationary employee\'s contract early?"',
                    '"What should a commercial lease in Makati include?"',
                    '"Do I need to register my online store with the BIR?"',
                ],
                'quote' => null,
                'after' => 'You\'ll get a plain-language answer, and every claim links to the Philippine law or ruling it comes from, so you can check it yourself.',
                'cta_label' => 'Ask your first question',
                'cta_path' => '/chat',
                'postscript' => 'If you have questions, just reply. A real person reads every email.',
            ],
            2 => [
                'subject' => 'Don\'t take our word for it',
                'preview' => 'Every answer shows you exactly where it came from.',
                'heading' => 'Answers you can check',
                'paragraphs' => [
                    'Most AI tools give you a confident answer and leave you to guess whether it\'s right. Batayan works differently: each point in an answer carries a citation to the law, regulation, or Supreme Court decision behind it. Click one and you\'ll see the source.',
                    'That matters when the answer decides whether you sign a contract, let an employee go, or file on time.',
                    'Try something you actually need this week, in English, Tagalog, or Taglish:',
                ],
                'bullets' => [],
                'quote' => 'My supplier missed delivery twice. What are my options under our contract and the Civil Code?',
                'after' => null,
                'cta_label' => 'Open Batayan',
                'cta_path' => '/chat',
                'postscript' => null,
            ],
            3 => [
                'subject' => 'Your next contract, drafted in 5 minutes',
                'preview' => 'Employment contracts, NDAs, leases — filled in from a short form.',
                'heading' => 'Your first document',
                'paragraphs' => [
                    'Batayan drafts the documents Philippine businesses use every week:',
                ],
                'bullets' => [
                    'Employment contracts (probationary, regular, project-based)',
                    'NDAs and service agreements',
                    'Lease agreements',
                    'Demand letters and HR notices',
                ],
                'quote' => null,
                'after' => 'Answer a few questions and you get a complete draft that follows Philippine law. Required sections are enforced, and every draft includes a note for lawyer review before you sign. Export to Word or PDF when you\'re done.',
                'cta_label' => 'Draft a document',
                'cta_path' => '/templates',
                'postscript' => 'P.S. Already have a contract from someone else? Upload it and ask, "What should I watch out for in this?"',
            ],
            4 => [
                'subject' => 'The deadlines hiding in your contracts',
                'preview' => 'Upload a document and Batayan finds the dates for you.',
                'heading' => 'Never miss a deadline',
                'paragraphs' => [
                    'Renewal dates, payment terms, and notice periods are easy to miss when they\'re buried on page 7 of a lease.',
                    'Upload your contracts, permits, or notices (PDFs, Word files, or a phone photo of a paper document) and Batayan will:',
                ],
                'bullets' => [
                    'File each document into the right matter automatically',
                    'Pull out the deadlines and turn them into to-dos',
                    'Answer questions about the document, pointing back to the exact page',
                ],
                'quote' => null,
                'after' => 'Your files are encrypted on upload and are never used to train AI models.',
                'cta_label' => 'Upload a document',
                'cta_path' => '/files',
                'postscript' => 'You\'re about halfway through your trial. Reply and tell us what you\'re using Batayan for, and we\'ll send tips specific to it.',
            ],
            default => [
                'subject' => 'A licensed lawyer, without the retainer',
                'preview' => 'Have a licensed lawyer vet your document before you sign.',
                'heading' => 'When you need a lawyer',
                'paragraphs' => [
                    'Some documents need more than a good draft. For those, Batayan connects you with licensed Philippine lawyers who will vet them:',
                ],
                'bullets' => [
                    'Check your draft for completeness and legal issues',
                    'Flag what needs fixing before you sign',
                ],
                'quote' => null,
                'after' => 'You pay per request, with no retainer and no law-firm hourly rates.',
                'cta_label' => 'Send a document for vetting',
                'cta_path' => '/vetting',
                'secondary_label' => 'See plans',
                'secondary_path' => '/choose-plan',
                'postscript' => $daysLeft !== null && $daysLeft > 0
                    ? "Your trial ends in {$daysLeft} ".($daysLeft === 1 ? 'day' : 'days').'. If Batayan has been useful, this is a good time to pick a plan so your matters and documents carry straight on.'
                    : null,
            ],
        };
    }
}
