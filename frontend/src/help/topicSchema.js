/**
 * The shape of one User Manual topic. Every field except id/section/title/summary
 * is optional; TopicView renders only what a topic provides, in this order.
 *
 * Text fields accept **bold** and `code` markup (see RichText).
 *
 * @typedef {object} Topic
 * @property {string}   id         URL slug: /help/{id}
 * @property {string}   section    chapter id from sections.js
 * @property {string}   title
 * @property {string}   summary    one or two sentences: what it is and why you use it
 * @property {string[]} [keywords] extra words people may search for (synonyms, abbreviations)
 * @property {string}   [module]   'inventory' | 'finance' — hide unless that module is on
 * @property {string}   [where]    menu path, e.g. 'Inventory › GRN'
 * @property {string}   [route]    app route for the "Open this screen" button
 * @property {string}   [who]      who can do it (permission / role in plain words)
 * @property {string[]} [intro]    paragraphs
 * @property {string[]} [before]   what must exist first
 * @property {object}   [flow]     FlowDiagram spec
 * @property {object}   [sketch]   ScreenSketch spec (numbered callouts)
 * @property {Array<string|{text:string,n?:number,tip?:string}>} [steps]
 * @property {{scenario:string, table?:{head:string[],rows:string[][]}, result?:string|string[]}} [example]
 * @property {string[]} [howItWorks] what changes in the system (stock, balances, ledger)
 * @property {object}   [statuses] StatusDiagram spec
 * @property {Array<{title?:string, head:string[], rows:string[][]}>} [tables]
 * @property {string[]} [rules]   rules and limits the system enforces
 * @property {string[]} [tips]
 * @property {Array<{q:string,a:string}>} [faq]
 * @property {string[]} [related] topic ids
 * @property {boolean}  [showSupport] render the support contact card in the topic
 */
export {}
