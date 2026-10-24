import { TaskClosed } from './api.js';
import { openBrowser } from './browser.js';
import { assertCompany, fillForm, loadMap, need, readIdentity, statusOf } from './cabinet.js';
import { readBalances, readNotices } from './balances.js';
import { openReporting, readJournal, toSnapshot } from './journal.js';
import { payTax } from './pay.js';
import { readCard } from './card.js';
import { switchToEntrepreneur } from './entrepreneur.js';
import { readPayments } from './payments.js';
import { signIn } from './login.js';
import { formFields, previousMonth, readTurnover, sendTurnover, toDraft } from './turnover.js';

/**
 * Runs one claimed task. Laravel decided what is allowed; this only carries it out in a visible browser.
 *
 * Order for a submission (each step may stop the task; none after the form check is ever repeated):
 *   sign in with E-IMZO -> check the cabinet is this company's -> fill the approved values -> read validation
 *   messages -> send the values read back to Laravel (it compares them with the approved report) -> only on
 *   "proceed": press the cabinet's sign button, the accountant confirms in E-IMZO -> read the cabinet's answer.
 */
export async function runTask(api, task, log = console.log) {
  const say = async (kind, message, waitingFor = null) => {
    log(`[${task.id}] ${kind}: ${message}`);
    await api.event(task.id, kind, message, waitingFor); // throws TaskClosed when a person cancelled
  };

  let browser = null;
  let signed = false; // true once Laravel allowed signing: from here a failure means "outcome unknown"

  try {
    browser = await openBrowser();
    const { page } = browser;
    const { company } = task.payload;

    await signIn(page, { tin: company.tin, loginType: task.payload.portal?.login_type }, say);

    const map = await loadMap();
    const identity = await readIdentity(page, map);
    assertCompany(identity, company);
    await say('progress', 'The cabinet belongs to the right company');

    // An entrepreneur's debts, demands, payment form and reports are in the entrepreneur's own cabinet, one switch away.
    const entrepreneur = task.payload.portal?.entrepreneur === true;
    if (entrepreneur) await switchToEntrepreneur(page, say);

    if (task.type === 'pay') {
      // A tax payment the owner approved in Bugalter. The cabinet's own form and confirmation window are filled and read; Laravel
      // compares them with the approval before the agent presses the confirm button (api.paymentCheck inside payTax).
      const result = await payTax(page, task, { api, say });
      if (!result.checked) await api.complete(task.id, result); // a check without paying was closed by the server already
      return;
    }

    if (task.type === 'rehearsal') {
      const entry = need(map.reporting?.entry_text?.[entrepreneur ? 'entrepreneur' : 'individual'], 'reporting.entry_text.entrepreneur');
      const reporting = await openReporting(browser.context, page, entry, say);
      const parsed = await readTurnover(reporting, map, task.payload.month, say); // opens the form and chooses the month; saves nothing
      await api.complete(task.id, { draft: toDraft(parsed, map.forms.turnover.wefo.form_code) });
      return;
    }

    if (task.type === 'portal_check') {
      const loginType = task.payload.portal?.login_type ?? 'legal';
      const entryKey = entrepreneur ? 'entrepreneur' : loginType;
      const entry = need(map.reporting?.entry_text?.[entryKey], `reporting.entry_text.${entryKey}`);

      // Everything the cabinet says about the taxpayer, all taxes: what is owed, what the tax authority wrote, what was filed.
      await say('progress', 'Reading the debts and payments of every tax');
      const balances = entrepreneur ? await readCard(page, { district: task.payload.company?.district ?? '' }) : await readBalances(page);
      await say('progress', 'Reading the payment orders');
      const payments = await readPayments(page);
      await say('progress', 'Reading the letters of the tax authority');
      const messages = await readNotices(page);

      const reporting = await openReporting(browser.context, page, entry, say);
      await say('progress', 'Reading the report journal');
      const rows = await readJournal(reporting, { formTypes: map.reporting.form_types, entity: loginType });

      // The cabinet calculates the entrepreneur's turnover tax itself. For the month before this one, if it was not filed, read
      // that calculation (nothing is saved or sent) so the accountant can check and approve it in Bugalter.
      const drafts = [];
      const wefo = map.forms?.turnover?.wefo;
      const { year, month } = previousMonth();
      const start = `${year}-${String(month).padStart(2, '0')}-01`;
      if (wefo && loginType === 'individual' && !rows.some((r) => r.type === 'turnover' && r.period_start === start)) {
        try {
          await say('progress', 'Reading the cabinet\'s own calculation for the last month');
          const parsed = await readTurnover(reporting, map, { year, month }, say);
          if (parsed.tin && identity.tin && parsed.tin !== identity.tin) throw new Error('The form belongs to another taxpayer.');
          drafts.push(toDraft(parsed, wefo.form_code));
        } catch (error) {
          await say('progress', `The cabinet's own calculation could not be read: ${error.message}`);
        }
      }

      await api.complete(task.id, { snapshot: { ...toSnapshot(rows, identity), balances, messages, drafts, payments } });
      return;
    }

    if (task.type !== 'submit_report') throw new Error(`Unknown task type ${task.type}`);

    const wefo = map.forms?.turnover?.wefo;
    const reportType = task.payload.report.report.type;

    // Turnover tax of an entrepreneur: the cabinet fills the form itself once the period is chosen; the form's own figures are read.
    let fields;
    let reporting = null;
    let journalBefore = [];
    if (reportType === 'turnover' && wefo && (task.payload.portal?.login_type ?? 'legal') === 'individual') {
      const { period_start: start, period_end: end } = task.payload.report.report;
      if (start.slice(0, 7) !== end.slice(0, 7)) throw new Error('Only a one-month turnover report can be filed in the cabinet this way.');

      const entry = need(map.reporting?.entry_text?.[entrepreneur ? 'entrepreneur' : 'individual'], 'reporting.entry_text.entrepreneur');
      reporting = await openReporting(browser.context, page, entry, say);
      journalBefore = (await readJournal(reporting, { formTypes: map.reporting.form_types, entity: 'individual' })).map((r) => r.report_number).filter(Boolean);

      await say('progress', 'Choosing the period in the cabinet form');
      fields = formFields(await readTurnover(reporting, map, { year: Number(start.slice(0, 4)), month: Number(start.slice(5, 7)) }, say));
    } else {
      await say('progress', 'Filling the form with the approved values');
      fields = await fillForm(page, map, task.payload);

      const messages = await validationMessages(page, map, reportType);
      if (messages.length) throw new Error(`The cabinet reported validation errors: ${messages.join(' | ')}`);
    }

    // The form is filled in the real cabinet; nothing is signed. The accountant answers "send" or "do not send" in the
    // panel. "Do not send" closes the task on the server (TaskClosed ends it here); no answer in time ends it too.
    const preview = await api.preview(task.id, fields);
    if (preview.mismatches?.length) throw new Error(`The filled form does not match the approved report (${preview.mismatches.join(', ')}). Not sent.`);
    await say('progress', 'The form is filled. Waiting for the accountant to decide in the panel');
    await waitForDecision(api, task.id);

    const check = await api.formCheck(task.id, fields);
    if (!check.proceed) throw new Error(`Not signed: the form does not match the approved report (${check.mismatches.join(', ')}).`);
    signed = true;

    await say('progress', 'The form matches the approved report. Signing');
    const result = reporting
      ? await sendTurnover(reporting, map, { period_start: task.payload.report.report.period_start }, { before: journalBefore, say })
      : await signAndRead(page, map, reportType, say);
    await api.complete(task.id, result);
  } catch (error) {
    if (error instanceof TaskClosed) {
      log(`[${task.id}] stopped: ${error.message}`);
      return;
    }
    // Laravel treats a failure after the form check as "outcome unknown" and never as "not sent".
    const reason = signed ? `${error.message} (after signing started)` : error.message;
    await api.fail(task.id, reason, error.seenIdentifier ?? null).catch((e) => log(`[${task.id}] could not report the failure: ${e.message}`));
  } finally {
    await browser?.context.close().catch(() => {});
  }
}

export const DECISION_WAIT_MS = 30 * 60_000;

/** Polls until the accountant says "send". Anything else (decline, cancel, timeout) means nothing is sent. */
export async function waitForDecision(api, taskId, { timeoutMs = DECISION_WAIT_MS, everyMs = 3_000, sleep = (ms) => new Promise((r) => setTimeout(r, ms)) } = {}) {
  const until = Date.now() + timeoutMs;

  while (Date.now() < until) {
    const { decision } = await api.decision(taskId); // throws TaskClosed when the accountant said "do not send"
    if (decision === 'send') return;
    if (decision) throw new Error(`Unexpected answer "${decision}"; not sent.`);
    await sleep(everyMs);
  }

  throw new Error('No answer from the accountant in time. Nothing was sent.');
}

async function validationMessages(page, map, type) {
  const selector = map.forms[type]?.validation_messages;
  if (!selector) return [];
  return (await page.locator(selector).allInnerTexts()).map((t) => t.trim().slice(0, 200)).filter(Boolean);
}

async function signAndRead(page, map, type, say) {
  const form = map.forms[type];
  if (!form.sign_button || !form.result?.status) throw new Error(`The cabinet map has no sign button or result for "${type}" yet.`);

  await page.locator(form.sign_button).first().click({ timeout: 15_000 });
  await say('needs_user', 'Confirm the signature in the E-IMZO window', 'eimzo_confirmation');

  const status = page.locator(form.result.status).first();
  await status.waitFor({ timeout: 5 * 60_000 });
  const text = (await status.innerText()).trim();

  const receipt = form.result.receipt ? ((await page.locator(form.result.receipt).first().innerText().catch(() => '')) || '').trim().slice(0, 80) : '';
  return { status: statusOf(text, map.reports_list.status_words ?? {}), receipt: receipt || null, errors: [] };
}
