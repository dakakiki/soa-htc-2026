import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { i18n } from '@/i18n';
import StudentTestPage from '@/pages/student/StudentTestPage.vue';
import * as studentApi from '@/api/student';
import { loadDraft, saveDraft, type AttemptAnswers } from '@/utils/attemptDraft';
import type { SubmitAnswer } from '@/types/models';

/**
 * The exam screen's memory: what it keeps while a competitor answers, and what it
 * puts back when the attempt resumes.
 *
 * This is the half no PHP test can reach. Answers only ever arrive in the
 * database from `submit`, and the resume payload deliberately carries no given
 * answer — so everything below happens between the browser and the device it is
 * running on, and nowhere else.
 */

vi.mock('vue-router', () => ({
    useRoute: () => ({ params: { testId: '13' } }),
    useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
    RouterLink: { template: '<a><slot /></a>' },
}));

vi.mock('@/api/student', () => ({
    startTest: vi.fn(),
    submitAttempt: vi.fn(),
}));

const ATTEMPT = 7933;
const MC = 311;
const GAPS = 312;
const ESSAY = 313;

const OPTIONS = [
    { id: 961, text: 'dumps' },
    { id: 962, text: 'skips' },
];

/** One attempt, one question of each kind, and two blanks in the gapped one. */
function payload(attemptId = ATTEMPT) {
    return {
        attempt: {
            id: attemptId,
            status: 'in_progress',
            expires_at: new Date(Date.now() + 30 * 60_000).toISOString(),
            remaining_seconds: 1800,
        },
        test: { id: 13, title: 'Use of English', description: '', duration: 30 },
        questions: [
            {
                id: MC,
                title: null,
                description: 'We throw our rubbish into large containers.',
                question_type: 'multiple_choice',
                answer_numbering: 'upper_alpha',
                points: 1,
                position: 1,
                image_url: null,
                audio_url: null,
                options: OPTIONS,
            },
            {
                id: GAPS,
                title: null,
                description: 'She [answer] to the shop and [answer] home.',
                question_type: 'gap_filling',
                answer_numbering: null,
                points: 2,
                position: 2,
                image_url: null,
                audio_url: null,
                options: [],
            },
            {
                id: ESSAY,
                title: null,
                description: 'Write about your town.',
                question_type: 'essay',
                answer_numbering: null,
                points: 5,
                position: 3,
                image_url: null,
                audio_url: null,
                options: [],
            },
        ],
        notes: [],
    };
}

function sheet(over: Partial<AttemptAnswers> = {}): AttemptAnswers {
    return { mc: {}, gaps: {}, essay: {}, ...over };
}

async function open(attemptId = ATTEMPT): Promise<VueWrapper> {
    vi.mocked(studentApi.startTest).mockResolvedValue({ data: payload(attemptId) } as never);
    vi.mocked(studentApi.submitAttempt).mockResolvedValue({ data: { attempt: { id: attemptId } } } as never);

    const wrapper = mount(StudentTestPage, { global: { plugins: [createPinia(), i18n] } });
    await flushPromises();

    return wrapper;
}

async function press(wrapper: VueWrapper, key: string): Promise<void> {
    const label = i18n.global.t(key);
    const button = wrapper.findAll('button').find((candidate) => candidate.text().trim() === label);

    if (button === undefined) {
        throw new Error(`no button reading "${label}"`);
    }

    await button.trigger('click');
}

/**
 * Hand the test in and give back the answers that actually went to the server.
 *
 * 🔴 This is the assertion that matters for a restored sheet, and the reason the
 * obvious one is not enough: an option id the question no longer offers cannot be
 * seen in the page at all — no radio carries it — so a screen that kept it would
 * look identical and submit it anyway. It would also count the question as
 * answered, and quietly drop it out of the warning listing what is still blank.
 */
async function handIn(wrapper: VueWrapper): Promise<SubmitAnswer[]> {
    await press(wrapper, 'student.test.handIn');
    await press(wrapper, 'student.test.handInNow');
    await flushPromises();

    return vi.mocked(studentApi.submitAttempt).mock.calls[0][2];
}

/** Which option ids are selected, in the order they are drawn. */
function chosen(wrapper: VueWrapper): number[] {
    return wrapper
        .findAll('input[type="radio"]')
        .map((input, index) => ((input.element as HTMLInputElement).checked ? OPTIONS[index].id : 0))
        .filter(Boolean);
}

const blanks = (wrapper: VueWrapper): string[] =>
    wrapper.findAll('input[type="text"]').map((input) => (input.element as HTMLInputElement).value);

const written = (wrapper: VueWrapper): string => (wrapper.find('textarea').element as HTMLTextAreaElement).value;

/** The debounce is 400 ms of real time; nothing here is worth faking a clock for. */
const afterTheDebounce = (): Promise<unknown> => new Promise((resolve) => setTimeout(resolve, 450));

beforeEach(() => {
    localStorage.clear();
});

afterEach(() => {
    localStorage.clear();
});

describe('resuming an attempt', () => {
    it('puts back every kind of answer the competitor had given', async () => {
        saveDraft(
            ATTEMPT,
            sheet({
                mc: { [MC]: [962] },
                gaps: { [GAPS]: ['walked', 'ran'] },
                essay: { [ESSAY]: 'My town is small.' },
            }),
        );

        const wrapper = await open();

        expect(chosen(wrapper)).toEqual([962]);
        expect(blanks(wrapper)).toEqual(['walked', 'ran']);
        expect(written(wrapper)).toBe('My town is small.');
    });

    it('draws an empty sheet when this device kept nothing', async () => {
        const wrapper = await open();

        expect(chosen(wrapper)).toEqual([]);
        expect(blanks(wrapper)).toEqual(['', '']);
        expect(written(wrapper)).toBe('');
    });

    /**
     * A draft is stored under its attempt's own id, so one attempt's answers can
     * never be drawn into another.
     */
    it('will not take a sheet belonging to a different attempt', async () => {
        saveDraft(ATTEMPT, sheet({ essay: { [ESSAY]: 'somebody else was here' } }));

        const wrapper = await open(8888);

        expect(written(wrapper)).toBe('');
    });
});

/**
 * 🪤 What comes back is measured against the question in front of it, because an
 * editor may have changed the test since the draft was written.
 */
describe('what a resumed sheet is allowed to restore', () => {
    it('drops an option the question no longer offers', async () => {
        saveDraft(ATTEMPT, sheet({ mc: { [MC]: [999] } }));

        const wrapper = await open();

        expect(chosen(wrapper)).toEqual([]);
        expect(await handIn(wrapper)).toContainEqual({ question_id: MC, response: { selected: [] } });
    });

    it('keeps an option that is still offered', async () => {
        saveDraft(ATTEMPT, sheet({ mc: { [MC]: [962] } }));

        const wrapper = await open();

        expect(await handIn(wrapper)).toContainEqual({ question_id: MC, response: { selected: [962] } });
    });

    it('cuts a kept answer down when the question lost a blank', async () => {
        saveDraft(ATTEMPT, sheet({ gaps: { [GAPS]: ['one', 'two', 'three'] } }));

        const wrapper = await open();

        expect(blanks(wrapper)).toEqual(['one', 'two']);
    });

    it('pads a kept answer out when the question gained one', async () => {
        saveDraft(ATTEMPT, sheet({ gaps: { [GAPS]: ['only this'] } }));

        const wrapper = await open();

        expect(blanks(wrapper)).toEqual(['only this', '']);
    });
});

describe('keeping the sheet as it is written', () => {
    it('writes an answer a moment after it is given', async () => {
        const wrapper = await open();

        await wrapper.findAll('input[type="radio"]')[1].trigger('change');
        await wrapper.find('textarea').setValue('A few words.');
        await afterTheDebounce();

        expect(loadDraft(ATTEMPT)).toEqual(
            sheet({ mc: { [MC]: [962] }, gaps: { [GAPS]: ['', ''] }, essay: { [ESSAY]: 'A few words.' } }),
        );
    });

    it('gives the sheet up once it is with the server', async () => {
        const wrapper = await open();

        await wrapper.find('textarea').setValue('handed in');
        await handIn(wrapper);

        expect(loadDraft(ATTEMPT)).toBeNull();
    });

    it('writes the last keystrokes on the way out, without waiting for the pause', async () => {
        const wrapper = await open();

        await wrapper.find('textarea').setValue('typed and gone');
        window.dispatchEvent(new Event('beforeunload'));

        expect(loadDraft(ATTEMPT)?.essay[ESSAY]).toBe('typed and gone');
    });
});
