/**
 * FAQ list.
 *
 * Built on `<details>`/`<summary>`: open and close behaviour, keyboard support
 * and screen-reader semantics all come from the browser, with no JavaScript and
 * no ARIA to get wrong. Search engines see the answers in the HTML, which is
 * also what makes the FAQPage structured data honest.
 */
export function FaqList({ faqs, heading }: { faqs: { question: string; answer: string }[]; heading?: string }) {
  if (faqs.length === 0) return null;

  return (
    <section className="section-tight" aria-labelledby={heading ? 'faq-heading' : undefined}>
      {heading ? (
        <div className="section-head">
          <h2 id="faq-heading">{heading}</h2>
        </div>
      ) : null}

      <div>
        {faqs.map((faq) => (
          <details key={faq.question} className="faq-item">
            <summary>{faq.question}</summary>
            <div className="faq-item__body">
              <p>{faq.answer}</p>
            </div>
          </details>
        ))}
      </div>
    </section>
  );
}
