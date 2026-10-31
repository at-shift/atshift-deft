// Only the current content and its pinned components cross the server boundary.
// Browser history and local review flags are never imported as server authority.
export function snapshotFor(record) {
  const article = record.article;
  const used = new Set(
    article.blocks.map((b) => `${b.componentId}@${b.componentRevision}`),
  );
  return {
    article: {
      id: article.id,
      revision: article.revision,
      title: article.title,
      blocks: article.blocks.map((b) => ({
        id: b.id,
        componentId: b.componentId,
        componentRevision: b.componentRevision,
        variantId: b.variantId,
        values: Object.fromEntries(
          Object.keys(b.values)
            .sort()
            .map((key) => [
              key,
              {
                value: b.values[key].value,
                alt: b.values[key].alt,
              },
            ]),
        ),
      })),
    },
    components: record.components
      .filter((c) => used.has(`${c.id}@${c.revision}`))
      .sort((a, b) =>
        `${a.id}@${a.revision}`.localeCompare(`${b.id}@${b.revision}`, "en"),
      )
      .map((c) => ({
        id: c.id,
        key: c.key,
        name: c.name,
        purpose: c.purpose,
        html: c.html,
        css: c.css,
        fields: c.fields.map((f) => ({
          id: f.id,
          key: f.key,
          label: f.label,
          type: f.type,
          required: f.required,
          value: f.value,
          alt: f.alt,
        })),
        variants: c.variants.map((v) => ({
          id: v.id,
          name: v.name,
          classes: v.classes,
        })),
        revision: c.revision,
      })),
  };
}
