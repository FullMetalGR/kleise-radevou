import { Button } from '@/components/ui/button';
import { TextInput } from '@/components/ui/input';
import { type Found, matchesOf } from './search';
import type { HelpSession } from './session';
import type { HelpAnswer } from './types';
import type { HelpWords } from './words';

/*
 * The Answers tab: a search over the answers, then the answers under their topics, or, while
 * something is searched for, one list of the answers that match and a line counting them. An
 * answer shows its question; pressing it opens its paragraphs and closes the one open before. No
 * answer matching says so beside the button that opens a ticket, which the foot of the tab offers
 * too; a viewer with no tickets is offered neither.
 */

type Props = { session: HelpSession; words: HelpWords; tickets: boolean };

/** One answer's question, which opens and closes its paragraphs. */
function Item({ id, answer, open, onToggle }: { id: string; answer: HelpAnswer; open: boolean; onToggle: (id: string) => void }) {
    const body = `admin-help-answer-${id}`;

    return (
        <li className="admin-help-item border-b last:border-b-0">
            <h4>
                <button
                    type="button"
                    className="admin-help-question flex w-full items-start justify-between gap-3 rounded-md py-2.5 text-left text-sm font-medium outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                    aria-expanded={open}
                    aria-controls={body}
                    onClick={() => onToggle(id)}
                >
                    <span>{answer.question}</span>
                    <span className="shrink-0 text-muted-foreground" aria-hidden="true">
                        {open ? '-' : '+'}
                    </span>
                </button>
            </h4>
            <div id={body} hidden={!open} className="admin-help-answer flex flex-col gap-2 pb-3 text-sm text-muted-foreground">
                {answer.answer.map((paragraph, index) => (
                    <p key={index}>{paragraph}</p>
                ))}
            </div>
        </li>
    );
}

function Topics({ session }: { session: HelpSession }) {
    return (
        <div className="admin-help-topics flex flex-col gap-4">
            {session.topics.map((topic) => (
                <section key={topic.key} className="admin-help-topic flex flex-col">
                    <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">{topic.title}</h3>
                    <ul>
                        {topic.answers.map((answer) => {
                            const id = `${topic.key}-${answer.key}`;

                            return <Item key={id} id={id} answer={answer} open={session.answer === id} onToggle={session.toggleAnswer} />;
                        })}
                    </ul>
                </section>
            ))}
        </div>
    );
}

function Matches({ session, words, tickets, found }: Props & { found: Found[] }) {
    return (
        <div className="admin-help-matches flex flex-col gap-2">
            <div role="status" className="admin-help-count flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                {found.length > 0 ? words.choice('search.found', found.length) : words.text('search.none')}
            </div>
            {found.length === 0 && tickets && (
                <Button variant="primary" className="self-start" onClick={session.newTicket}>
                    {words.text('open')}
                </Button>
            )}
            <ul>
                {found.map(({ topic, answer }) => {
                    const id = `${topic}-${answer.key}`;

                    return <Item key={id} id={id} answer={answer} open={session.answer === id} onToggle={session.toggleAnswer} />;
                })}
            </ul>
        </div>
    );
}

export function Answers({ session, words, tickets }: Props) {
    const searching = matchesOf(session.topics, session.query);

    return (
        <div className="admin-help-answers flex flex-col gap-4">
            <TextInput
                type="search"
                value={session.query}
                onChange={(event) => session.search(event.target.value)}
                aria-label={words.text('search.label')}
                placeholder={words.text('search.label')}
                data-help-focus=""
            />
            {searching === null ? <Topics session={session} /> : <Matches session={session} words={words} tickets={tickets} found={searching} />}
            {tickets && (searching === null || searching.length > 0) && (
                <div className="admin-help-still flex flex-wrap items-center justify-between gap-2 border-t pt-4 text-sm">
                    <span>{words.text('still')}</span>
                    <Button onClick={session.newTicket}>{words.text('open')}</Button>
                </div>
            )}
        </div>
    );
}
