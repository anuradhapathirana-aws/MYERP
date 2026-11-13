/**
 * User Manual — Business Flows: the end-to-end picture that links documents.
 * Steps tagged 'Finance' only apply when the Finance module is switched on.
 */
export const topics = [
  {
    id: 'flow-purchase-to-pay',
    section: 'flows',
    title: 'Purchase to Pay',
    keywords: ['purchase', 'buying', 'procurement', 'process', 'workflow', 'supplier', 'pay', 'PR', 'PO', 'GRN'],
    summary: 'From asking to buy something to paying the supplier, in the order the documents are made.',
    flow: {
      title: 'Purchase to Pay',
      steps: [
        { label: 'Purchase Request', sub: 'Submit → Approve', tone: 'slate' },
        { label: 'Purchase Order', sub: 'Send / Confirm to supplier', tone: 'blue' },
        { label: 'GRN', sub: 'Goods received: **stock in**, supplier owed', tone: 'green' },
        { label: 'Costing', sub: 'Imports: landed cost & selling price', tone: 'violet' },
        { label: 'Supplier Payment', sub: 'Cash / cheque / transfer / set-off', tone: 'teal' },
        { label: 'Bank Reconciliation', sub: 'Match the bank statement', tone: 'indigo', tag: 'Finance' },
      ],
      branches: [
        { at: 2, label: 'Supplier Return', sub: 'Send goods back (PRN)', tone: 'red' },
        { at: 2, label: 'Stock Reconciliation', sub: 'Correct qty / weight', tone: 'amber' },
        { at: 3, label: 'Bills', sub: 'Freight, duty, clearing', tone: 'amber' },
      ],
      legend: 'Costing is for imported goods. Local purchases usually go straight from GRN to payment.',
    },
    steps: [
      '**Purchase Request (PR):** someone asks to buy; a supervisor approves it.',
      '**Purchase Order (PO):** load the approved PR, choose the supplier and prices, and send it.',
      '**GRN:** when goods arrive, record what actually came in. Confirming adds the stock and records what you owe the supplier.',
      '**Costing (imports):** add freight, insurance, duty and other charges to find the landed cost and set selling prices.',
      '**Supplier Payment:** pay one or more GRNs by cash, cheque or transfer, or set off a credit note.',
      '**Bank Reconciliation (Finance):** tick off the payment when it appears on the bank statement.',
    ],
    example: {
      scenario: 'The store needs 500 m of navy cotton twill.',
      table: {
        head: ['Step', 'Document', 'What happens'],
        rows: [
          ['1', 'PR-2026-0012 (Ref-0012)', 'Store keeper requests 500 m; the manager approves.'],
          ['2', 'PO-2026-0041', 'Sent to Lanka Textiles at Rs 850/m.'],
          ['3', 'GRN-2026-0035', '480 m arrive in 6 rolls. Stock +480 m. The PO shows **Partially Received**.'],
          ['4', 'PAY-0118', 'Rs 408,000 paid by cheque. The GRN shows paid.'],
        ],
      },
      result: 'Stock, the PO status and the supplier balance all update by themselves at each step.',
    },
    related: ['flow-import-landed-cost', 'flow-returns', 'gs-draft-confirm'],
  },
  {
    id: 'flow-order-to-cash',
    section: 'flows',
    title: 'Order to Cash',
    keywords: ['sales', 'selling', 'customer', 'order', 'delivery', 'invoice', 'receipt', 'collection', 'process', 'workflow', 'SO', 'DO'],
    summary: 'From a customer’s enquiry to the money in the bank, in the order the documents are made.',
    flow: {
      title: 'Order to Cash',
      steps: [
        { label: 'Proforma Invoice', sub: 'Optional quotation', tone: 'slate' },
        { label: 'Sales Order', sub: 'Rolls reserved', tone: 'blue' },
        { label: 'Delivery Order', sub: 'Confirm & Ship: **stock out**', tone: 'amber' },
        { label: 'Invoice', sub: 'Issue: customer owes us', tone: 'green' },
        { label: 'Customer Receipt', sub: 'Cash / cheque / card / transfer', tone: 'teal' },
        { label: 'Bank Deposit', sub: 'Bank the cash & cheques', tone: 'violet', tag: 'Finance' },
        { label: 'Bank Reconciliation', sub: 'Cheques realised or returned', tone: 'indigo', tag: 'Finance' },
      ],
      branches: [
        { at: 3, label: 'Customer Return', sub: 'Goods back (SRN) → credit note', tone: 'red' },
      ],
    },
    steps: [
      '**Proforma Invoice (optional):** send prices to the customer before they commit.',
      '**Sales Order (SO):** record the order. The rolls you pick are reserved as soon as it is saved, so nobody else sells them.',
      '**Delivery Order (DO):** pick the goods and **Confirm & Ship**. This is when stock goes down.',
      '**Invoice:** invoice the delivery order. Issuing it records what the customer owes.',
      '**Customer Receipt:** record the payment against one or more invoices.',
      '**Bank Deposit (Finance):** take the cash and cheques to the bank.',
      '**Bank Reconciliation (Finance):** mark cheques as realised, or returned if they bounce.',
    ],
    example: {
      scenario: 'Sunil Garments orders 3 rolls of navy twill.',
      table: {
        head: ['Step', 'Document', 'What happens'],
        rows: [
          ['1', 'SO-2026-0210', 'Three rolls (152 m) are reserved for Sunil Garments.'],
          ['2', 'DO-2026-0198', 'Confirmed & shipped: stock −152 m. The SO becomes **Completed**.'],
          ['3', '26NOV_CUS0012_0457', 'Invoice issued for Rs 182,400 incl. VAT.'],
          ['4', 'RCP-0390', 'Cheque received. The invoice becomes **Paid**.'],
          ['5', 'BD-2026-0077 → BR-2026-0010', 'Cheque deposited, then realised on the bank statement.'],
        ],
      },
    },
    rules: [
      'One delivery order is invoiced by exactly one invoice.',
      'Stock goes down at the **Delivery Order**, not at the Sales Order or the Invoice.',
    ],
    related: ['flow-cheques', 'flow-returns', 'gs-draft-confirm'],
  },
  {
    id: 'flow-import-landed-cost',
    section: 'flows',
    title: 'Imports and landed cost',
    keywords: ['import', 'landed cost', 'costing', 'FOB', 'CIF', 'freight', 'duty', 'clearing', 'shipment', 'selling price', 'SSCL', 'VAT', 'bill'],
    summary: 'How the full cost of an imported shipment is built up and turned into selling prices.',
    flow: {
      title: 'Import shipment',
      steps: [
        { label: 'PO', sub: 'FOB or CIF price', tone: 'blue' },
        { label: 'GRN', sub: 'Goods into store', tone: 'green' },
        { label: 'Bills', sub: 'Freight, insurance, duty, clearing', tone: 'amber', tag: 'Finance' },
        { label: 'Costing', sub: 'Pull GRNs + bill charges', tone: 'violet' },
        { label: 'Selling prices', sub: 'Set on the product price list', tone: 'teal' },
      ],
    },
    intro: [
      'The landed cost is everything it took to get the goods into your store: the supplier price **plus** freight, insurance, duty, clearing and other charges. Selling prices are based on it.',
    ],
    steps: [
      'Receive the shipment with a **GRN** as usual.',
      'Enter each service provider’s invoice (shipping line, clearing agent, customs) as a **Bill** in Finance and link its lines to the GRN.',
      'Create a **Costing**, choose FOB or CIF, and pull in the GRNs and the bill charges. Charges are shared over the products by value.',
      'Add the margin. The costing works out the price before tax (with SSCL) and after VAT.',
      'Confirm the costing. The landed cost and selling prices are saved on each product’s price list.',
    ],
    tables: [{
      title: 'How the price is built (per base unit)',
      head: ['Line', 'Meaning'],
      rows: [
        ['FOB / CIF price', 'What the supplier charged.'],
        ['+ Expenses', 'Freight, insurance, duty, clearing… shared over the products by value.'],
        ['+ Margin', 'Your profit.'],
        ['+ SSCL', 'The levy (1.25% by default), rounded up.'],
        ['= Price before tax', ''],
        ['+ VAT', ''],
        ['= Price after tax', 'The selling price including VAT.'],
      ],
    }],
    rules: [
      'A GRN can be in only one confirmed costing.',
      'A bill charge can be used in only one costing, and only for a GRN that is in that costing.',
    ],
    related: ['flow-purchase-to-pay'],
  },
  {
    id: 'flow-returns',
    section: 'flows',
    title: 'Returns: goods back to suppliers and from customers',
    keywords: ['return', 'returns', 'send back', 'damaged', 'credit note', 'refund', 'PRN', 'SRN', 'CCN', 'CN', 'supplier return', 'customer return'],
    summary: 'Goods can go back to a supplier or come back from a customer. Either way, stock, balances and credit notes are updated for you.',
    flow: {
      title: 'Supplier return',
      steps: [
        { label: 'Confirmed GRN', tone: 'green' },
        { label: 'Supplier Return (PRN)', sub: 'Choose lines, qty, reason', tone: 'red' },
        { label: 'Stock out', sub: 'At the GRN cost; partial rolls are cut', tone: 'amber' },
        { label: 'We owe less', sub: 'Any extra becomes a Supplier Credit Note (CN)', tone: 'teal' },
      ],
    },
    intro: [
      '**Customer returns** work the other way: a Customer Return (SRN) is raised against an issued or paid invoice. The goods go back into the store you choose, the customer owes less, and any amount already paid becomes a **Customer Credit Note (CCN)** they can use against a later invoice.',
    ],
    tables: [{
      head: ['', 'Supplier Return', 'Customer Return'],
      rows: [
        ['Raised against', 'One confirmed GRN', 'One issued or paid invoice'],
        ['Most you can return', 'Received − already returned', 'Invoiced − already returned'],
        ['Stock', 'Goes **out**', 'Comes back **in**'],
        ['Balance', 'You owe the supplier less', 'The customer owes you less'],
        ['If already paid', 'Supplier Credit Note (CN)', 'Customer Credit Note (CCN)'],
        ['Credit note used on', 'A later Supplier Payment (set-off)', 'A later Customer Receipt (set-off)'],
      ],
    }],
    related: ['flow-purchase-to-pay', 'flow-order-to-cash'],
  },
  {
    id: 'flow-cheques',
    section: 'flows',
    title: 'The life of a cheque',
    module: 'finance',
    keywords: ['cheque', 'check', 'bounce', 'bounced cheque', 'returned cheque', 'realised', 'realized', 'deposit', 'cheques in hand', 'uncleared', 'post-dated'],
    summary: 'How a customer’s cheque moves from the receipt to the bank, and what happens if it bounces.',
    flow: {
      title: 'Customer cheque',
      steps: [
        { label: 'Customer Receipt', sub: 'Cheque recorded: **Cheques in Hand**', tone: 'teal' },
        { label: 'Bank Deposit', sub: 'Tick the cheques you bank', tone: 'violet' },
        { label: 'Bank Reconciliation', sub: 'Match with the statement', tone: 'indigo' },
        { label: 'Realised', sub: 'Money is in the bank', tone: 'green' },
      ],
      branches: [
        { at: 2, label: 'Returned (bounced)', sub: 'The invoice becomes unpaid again', tone: 'red' },
        { at: 2, label: 'Not presented yet', sub: 'Stays on Uncleared Cheques', tone: 'amber' },
      ],
    },
    howItWorks: [
      'Until it is deposited, the cheque sits in **Cheques in Hand**.',
      'On the Bank Reconciliation you mark each cheque **Realised**, **Returned** or **Not Presented**.',
      'A **returned** cheque reverses the receipt’s effect: the invoices it paid become unpaid again, and the customer is flagged as having a returned cheque.',
      'Cheques you **issue** to suppliers follow the same idea: they stay on the **Uncleared Cheques** report until the bank shows them paid.',
    ],
    related: ['flow-order-to-cash'],
  },
]
