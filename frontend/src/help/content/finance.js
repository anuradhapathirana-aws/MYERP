/**
 * User Manual — Finance chapter (only loaded when the Finance module is on).
 *
 * Account codes, statuses, messages and posting rules were checked against
 * the Finance services, posting classes and screens.
 */
export const topics = [
  {
    id: 'fin-overview',
    section: 'finance',
    title: 'How Finance works with your documents',
    keywords: ['finance', 'accounting', 'ledger', 'journal', 'posting', 'automatic', 'double entry', 'review first', 'post immediately'],
    summary: 'Most accounting entries are made for you: when a document is confirmed, Finance writes its journal.',
    flow: {
      title: 'From document to ledger',
      steps: [
        { label: 'Confirm a document', sub: 'GRN, invoice, receipt, bill…', tone: 'blue' },
        { label: 'Journal created', sub: 'Accounts from Account Mapping', tone: 'violet' },
        { label: 'Review first: Draft JV', sub: 'Finance checks and posts', tone: 'amber' },
        { label: 'Posted', sub: 'In the Trial Balance', tone: 'green' },
      ],
    },
    intro: [
      'You do not type journals for normal business. Confirming a GRN, issuing an invoice, confirming a payment, receipt, bill or deposit each creates its own balanced journal (debits = credits).',
      'Mistakes are never deleted from the books. A confirmed document is **voided** or **cancelled**, which posts a **reversing** journal beside the original, so the history stays complete.',
    ],
    tables: [{
      title: 'Posting Mode (Finance › General Ledger › Account Mapping)',
      head: ['Mode', 'What happens'],
      rows: [
        ['Review first', 'A confirmed document creates a **Draft** journal. Finance checks it and posts it from **Journal Vouchers**. Use this for the first month.'],
        ['Post immediately', 'A confirmed document posts straight to the ledger. Switch to this once the mapping has been checked against real documents.'],
      ],
    }],
    related: ['fin-postings-reference', 'fin-account-mapping', 'fin-journal-voucher'],
  },

  {
    id: 'fin-coa',
    section: 'finance',
    title: 'Chart of Accounts',
    keywords: ['chart of accounts', 'COA', 'account category', 'control account', 'ledger account', 'account code', 'cash book', 'petty cash', 'bank account', 'cheques in hand', 'inactive account'],
    summary: 'Accounts are arranged in four levels. Every code is generated for you and shows exactly where the account sits.',
    where: 'Finance › Chart of Accounts › Account Categories / Control Accounts / Ledger Accounts',
    route: '/finance/ledger-accounts',
    who: 'View / Create / Edit Account Categories, Control Accounts and Ledger Accounts',
    flow: {
      title: 'How an account code is built',
      steps: [
        { label: 'Account Type', sub: '1 digit · e.g. 1 = Assets', tone: 'slate' },
        { label: 'Category', sub: '3 digits · 101', tone: 'blue' },
        { label: 'Control Account', sub: '5 digits · 10101', tone: 'violet' },
        { label: 'Ledger Account', sub: '8 digits · 10101001', tone: 'green' },
      ],
    },
    tables: [{
      title: 'Account types',
      head: ['First digit', 'Type', 'Normal balance'],
      rows: [['1', 'Assets', 'Debit'], ['2', 'Liabilities', 'Credit'], ['3', 'Equity', 'Credit'], ['4', 'Income', 'Credit'], ['5', 'Expenses', 'Debit']],
    }],
    steps: [
      '**Account Categories:** choose the account type and type the name. The 3-digit code is given automatically.',
      '**Control Accounts:** choose the category and type the name. The 5-digit code is given automatically.',
      '**Ledger Accounts:** choose the control account and type the name. The 8-digit code is given automatically. Only ledger accounts receive postings.',
      'For a cash or bank account, set **Selection of Cash**: **Cash Book** (tick whether it takes **Cash**, **Cheque** or both), **Petty Cash Book**, or **Bank** (with bank, branch and account number). These are the accounts offered as **Pay From**, **Deposit To** and **Deposit Into**.',
    ],
    example: {
      scenario: 'A new bank account for Commercial Bank:',
      table: { head: ['Level', 'Code', 'Name'], rows: [['Type', '1', 'Assets'], ['Category', '101', 'Current Assets'], ['Control', '10102', 'Cash & Bank'], ['Ledger', '10102004', 'Commercial Bank – Current A/C']] },
    },
    rules: [
      'Codes are never typed, never change, and are never reused, even after a delete.',
      'The account type of a category, and the parent of a control or ledger account, cannot be changed once the code is assigned.',
      'An account with postings can never be deleted. Set it **inactive** instead: history keeps it, and nothing new can post to it.',
      'A category or control account with accounts under it cannot be deleted.',
      'A cash book must accept cash, cheques, or both. A cash book that takes cheques is where received cheques wait (“Cheques in Hand”).',
    ],
    related: ['fin-account-mapping', 'fin-bank-deposit'],
  },

  {
    id: 'fin-account-mapping',
    section: 'finance',
    title: 'Account Mapping',
    keywords: ['account mapping', 'posting roles', 'mapping', 'accounts payable', 'accounts receivable', 'sales revenue', 'input tax', 'output tax', 'not mapped', 'party balances', 'one account per party', 'posting mode'],
    summary: 'Tells Finance which ledger account to use for each kind of posting, such as Accounts Payable or Sales Revenue.',
    where: 'Finance › General Ledger › Account Mapping',
    route: '/finance/account-mapping',
    who: 'View Account Mapping (read only). Changes are made by your IT support (super admin).',
    intro: [
      'Documents do not name accounts. They name a **posting role** (for example “Accounts Payable”), and this screen says which ledger account each role uses. The mapping arrives already filled in. Change it only if your chart really differs.',
    ],
    steps: [
      'Under **Posting Roles**, each role shows its **Ledger Account** and how many posted lines already use it (**In Use**).',
      'To change one, pick another account and save. The system refuses an account of the wrong type, for example Accounts Payable mapped to an income account.',
      'Under **Party Balances**, choose for Suppliers and Customers: **One shared account** for everyone, or **One account per party**.',
      'Under **Posting Mode**, choose **Review first** or **Post immediately**.',
      'The **Period Lock & Controls** card is on the same screen. See “Period lock and maker-checker”.',
    ],
    before: ['Mappings, Party Balances and Posting Mode decide where every journal is written, so only your **IT support (super admin)** can change them. Everyone else sees this screen read only. Ask IT support if a mapping needs to change.'],
    tables: [{
      title: 'Main posting roles',
      head: ['Role', 'Used by'],
      rows: [
        ['Accounts Payable', 'GRNs, bills, supplier and bill payments'],
        ['Accounts Receivable', 'Invoices, customer receipts and returns'],
        ['Purchases', 'GRNs (the goods)'],
        ['Sales Revenue / Sales Returns', 'Invoices / customer returns'],
        ['Input Tax Recoverable', 'Claimable VAT on GRNs and bills'],
        ['Output Tax Payable / SSCL Payable', 'VAT and SSCL on invoices'],
        ['Discount Allowed / Discount Received', 'Settlement discounts on receipts / payments'],
        ['Customer Advances / Supplier Advances', 'Credit notes, advances and over-payments'],
        ['Purchase Returns', 'Supplier returns'],
        ['Bank Charges / Interest Income', 'Entries added on a bank reconciliation'],
      ],
    }],
    rules: [
      'A missing mapping stops the document: “No ledger account is mapped to …”. Map the role, then confirm again.',
      'Changing a mapping is not retrospective. Entries already posted keep their account.',
    ],
    related: ['fin-overview', 'fin-postings-reference', 'ts-error-messages'],
  },

  {
    id: 'fin-controls',
    section: 'finance',
    title: 'Period lock and maker-checker',
    keywords: ['lock date', 'period lock', 'close period', 'month end', 'closed', 'maker checker', 'four eyes', 'approval', 'period closed'],
    summary: 'Lock a closed month so nothing can be posted into it, and optionally require a second person to confirm.',
    where: 'Finance › General Ledger › Account Mapping › Period Lock & Controls',
    route: '/finance/account-mapping',
    who: 'Period Lock – Manage (to lock a month). Maker-checker is switched on/off by your IT support (super admin).',
    steps: [
      'After closing a month (for example after filing the VAT return), set **Locked up to** to its last day and save.',
      'To switch **maker-checker** on or off, ask your IT support. Only a super admin can change it, because switching it off removes a fraud control.'
    ],
    example: {
      scenario: 'October 2026 is closed and reported. Set **Locked up to** = 31-10-2026.',
      result: [
        'Any GRN, invoice, payment, bill, voucher or void dated on or before 31-10-2026 is refused: “Period closed up to 31-10-2026. Choose a date after the lock date.”',
        'Corrections for October are posted in November instead.',
      ],
    },
    howItWorks: [
      'The lock protects **every** document that posts, including Inventory documents, because it is checked when the journal is written.',
      'Each bank account also has its own lock: nothing can be dated on or before its latest posted bank reconciliation.',
      'Maker-checker applies to Bills, Bill Payments, Bank Deposits and Bank Reconciliations: “Maker-checker is on: the person who entered this document cannot confirm it.”',
    ],
    rules: [
      'The lock date cannot be in the future.',
      'Moving the lock date back (re-opening a period) asks you to confirm first.',
    ],
    related: ['fin-account-mapping', 'ts-error-messages'],
  },

  {
    id: 'fin-journal-voucher',
    section: 'finance',
    title: 'Journal Vouchers',
    keywords: ['journal voucher', 'JV', 'journal entry', 'manual journal', 'depreciation', 'accrual', 'opening balance', 'adjustment', 'reverse journal', 'post draft', 'does not balance'],
    summary: 'Enter what no document produces, such as depreciation, accruals, opening balances and corrections. Review and post automatic drafts here too.',
    where: 'Finance › General Ledger › Journal Vouchers',
    route: '/finance/journal-entries',
    who: 'View / Create / Post / Reverse / Delete Journal Vouchers',
    sketch: {
      title: 'Journal Voucher',
      crumbs: 'Finance › Journal Vouchers › New',
      fields: [
        { label: 'Journal Date *', value: '30-11-2026', n: 1 },
        { label: 'Type *', value: 'Adjustment' },
        { label: 'Narration', value: 'Monthly depreciation, November', wide: true, n: 2 },
      ],
      table: { n: 3, cols: ['Ledger Account', 'Description', 'Debit', 'Credit'], rows: [['50301001 Depreciation', 'Vehicles', '25,000.00', ''], ['10201002 Acc. Depreciation – Vehicles', '', '', '25,000.00']] },
      footer: [{ label: 'Balanced — debits equal credits.', tone: 'green' }, { label: 'Post Journal', n: 4 }],
    },
    steps: [
      { text: 'Click **+ New Voucher**. Set the **Journal Date** (the period it hits) and the **Type**: General, Opening Balance or Adjustment.', n: 1 },
      { text: 'Type a clear **Narration** saying why the entry exists.', n: 2 },
      { text: 'Add at least two lines (`Alt+N`): **Ledger Account** and a **Debit** or a **Credit** amount.', n: 3 },
      { text: 'When the footer says **Balanced — debits equal credits.**, click **Post Journal**.', n: 4 },
    ],
    statuses: {
      main: ['Draft', 'Posted'],
      side: [{ from: 'Posted', to: 'Reversed', note: 'a reversing journal is posted' }],
      states: [
        { name: 'Draft', meaning: 'Only automatic journals in “Review first” mode. Post it (✓) or delete it.' },
        { name: 'Posted', meaning: 'In the books.' },
        { name: 'Reversed', meaning: 'Cancelled by a reversal journal, which is listed with type Reversal.' },
      ],
    },
    rules: [
      'A journal must balance: “This journal does not balance. Debits total … and credits total …”.',
      'A posted journal is never deleted. Use **Reverse** (choose a reversal date, which cannot be in the future or before the original date).',
      'Journals created by documents should be corrected through their document (void or cancel), not reversed here.',
    ],
    related: ['fin-overview', 'fin-trial-balance', 'fin-controls'],
  },

  {
    id: 'fin-trial-balance',
    section: 'finance',
    title: 'Trial Balance',
    keywords: ['trial balance', 'TB', 'balances', 'debit', 'credit', 'account balances', 'financial report'],
    summary: 'Every account’s debit or credit balance for a period, proving the books balance.',
    where: 'Finance › General Ledger › Trial Balance',
    route: '/finance/trial-balance',
    who: 'View Financial Reports',
    steps: [
      'Choose the date range (and location if needed) and apply.',
      'Each ledger account shows its balance on the debit or credit side, grouped under its control account.',
      'The footer confirms **Balanced — debits equal credits.**',
    ],
    howItWorks: ['Figures are calculated from posted journal lines every time you open it. Draft journals are not included.'],
    tips: ['If a figure looks wrong, find the journal in **Journal Vouchers** by its account, then open the document that created it.'],
    related: ['fin-journal-voucher'],
  },

  {
    id: 'fin-charge-types',
    section: 'finance',
    title: 'Charge Types',
    keywords: ['charge type', 'freight', 'insurance', 'customs duty', 'import VAT', 'clearing', 'demurrage', 'bank charges', 'landed cost', 'claimable tax', 'expense'],
    summary: 'The kinds of charge entered on bills, and whether each one becomes part of the goods’ cost.',
    where: 'Finance › Payables › Charge Types',
    route: '/finance/charge-types',
    who: 'View / Create / Edit Charge Types',
    tables: [
      {
        title: 'Treatment',
        head: ['Treatment', 'Where the money goes'],
        rows: [
          ['Landed cost', 'Part of what the goods cost. Pulled into the shipment’s **Costing**.'],
          ['Claimable tax', 'Goes to Input VAT and is set off against your output VAT.'],
          ['Expense', 'A cost of the period, never part of a product’s cost.'],
        ],
      },
      {
        title: 'Built-in charge types',
        head: ['Charge type', 'Treatment'],
        rows: [
          ['Freight, Insurance, Customs Duty, SSCL (Customs), Clearing & Transport', 'Landed cost'],
          ['Import VAT', 'Claimable tax'],
          ['Bank Charges, Bank Commission (LC / TT), Demurrage & Port Penalties', 'Expense'],
        ],
      },
    ],
    steps: [
      'Each charge type has a **Code**, **Name**, **Treatment** and **Account**.',
      'For landed costs, set the **Costing row (FOB / CIF)**: the Costing expense row it fills. A charge with no row for a costing type is not pulled into that costing.',
    ],
    rules: ['Built-in charge types cannot be deleted or have their code changed. Switch them off instead.'],
    related: ['fin-bills', 'pur-costing'],
  },

  {
    id: 'fin-bills',
    section: 'finance',
    title: 'Bills (supplier invoices for services and charges)',
    keywords: ['bill', 'supplier bill', 'BILL-', 'service invoice', 'freight bill', 'customs bill', 'clearing agent', 'expense', 'link GRN', 'import VAT', 'void bill', 'due date'],
    summary: 'Enter invoices that are not for stock, such as freight, customs, clearing, rent or repairs, and link import charges to their shipment.',
    where: 'Finance › Payables › Bills',
    route: '/finance/bills',
    who: 'View / Create / Edit / Confirm / Void Bills',
    sketch: {
      title: 'New Bill',
      crumbs: 'Finance › Bills › New',
      cards: [{ title: 'Bill Details', n: 1, fields: [
        { label: 'Supplier', value: 'Ceylon Clearing Agents' },
        { label: 'Document No', value: 'CCA/2026/881' },
        { label: 'Transaction Date', value: '12-11-2026' },
        { label: 'Due Date', value: '12-12-2026 (from credit period)' },
      ] }],
      table: { n: 2, cols: ['Charge Type', 'Account', 'GRN (shipment)', 'Tax', 'Amount'], rows: [['Clearing & Transport', 'Clearing & Transport In', 'GRN-2026-0040', 'No tax', '40,000.00'], ['Customs Duty', 'Import Duty & Levies', 'GRN-2026-0040', 'No tax', '477,000.00'], ['Import VAT', 'Input Tax Recoverable', 'GRN-2026-0040', '—', '687,200.00']] },
      footer: [{ label: 'Save as Draft', tone: 'slate' }, { label: 'Create & Close', n: 3 }],
    },
    steps: [
      { text: 'Click **+ Create Bill**. Choose the **Supplier**; the **Due Date** fills from their credit period. Type the supplier’s invoice number in **Document No** (or your own number if there is no invoice).', n: 1 },
      { text: 'Add a line per charge: **Charge Type** (fills the account), **GRN (shipment)** for import charges, **Tax**, and **Amount**. Tick **Amounts include VAT** if the invoice prices include VAT. Attach the invoice (PDF/JPG/PNG).', n: 2 },
      { text: 'Click **Create & Close** to confirm and post it (or **Save as Draft**). The footer shows the split: **Landed cost**, **Expense** and **VAT claimable**.', n: 3 },
    ],
    example: {
      scenario: 'The clearing agent’s bill above, total Rs 1,204,200.',
      table: { head: ['Account', 'Debit', 'Credit'], rows: [['Clearing & Transport In', '40,000', ''], ['Import Duty & Levies', '477,000', ''], ['Input Tax Recoverable', '687,200', ''], ['Accounts Payable – Ceylon Clearing Agents', '', '1,204,200']] },
      result: 'The clearing and duty lines are linked to GRN-2026-0040, so its Costing can **Pull** them into the landed cost.',
    },
    statuses: {
      main: ['Draft', 'Confirmed'],
      side: [{ from: 'Confirmed', to: 'Voided', note: 'reversed on a chosen date' }],
      auto: 'The list also shows payment progress: Unpaid, Partly Paid, Paid. “Overdue only” filters bills past their due date.',
    },
    rules: [
      'Only expense accounts can be used on a bill line.',
      'The same supplier invoice cannot be entered twice: “This supplier invoice is already entered as BILL-…”.',
      'A bill with payments cannot be voided until the payments are reversed.',
      'A bill pulled into a costing cannot be voided until it is removed from that costing.',
      'A line linked to a GRN must point to a confirmed GRN.',
    ],
    related: ['fin-bill-payments', 'fin-charge-types', 'flow-import-landed-cost', 'pur-costing'],
  },

  {
    id: 'fin-bill-payments',
    section: 'finance',
    title: 'Bill Payments',
    keywords: ['bill payment', 'BP-', 'pay bill', 'pay vendor', 'cheque', 'pay from', 'void payment', 'returned cheque'],
    summary: 'Pay one or more confirmed bills of a supplier from a cash book or bank account.',
    where: 'Finance › Payables › Bill Payments',
    route: '/finance/bill-payments',
    who: 'View / Create / Edit / Confirm / Void Bill Payments',
    steps: [
      'Click **+ Create Payment** and choose the **Vendor**. Their open bills load, oldest due first, with **Days** past due.',
      'Enter the **Payment Amount** per bill. Double-click a row to pay it in full, use the % box to pay the same share of every bill, or pay all in one click.',
      'Under **Payment Details**, choose the **Payment Mode**, the **Ledger Account** to pay from, and for a cheque the **Cheque No** (6 digits) and **Cheque Date**.',
      'Click **Create & Close** and answer **Pay now?**. (Without the Confirm permission the button is **Save as Draft**, and someone else confirms it.)',
    ],
    statuses: { main: ['Draft', 'Confirmed'], side: [{ from: 'Confirmed', to: 'Voided', note: 'bills get their balance back' }] },
    howItWorks: [
      'The journal debits Accounts Payable per bill and credits the cash book or bank paid from.',
      'Bill balances only move when the payment is confirmed. Drafts reserve nothing.',
    ],
    rules: [
      'The same cheque number cannot be used twice from the same account, including by Supplier Payments in Inventory.',
      'A cheque that bounces is marked **Return** on the Bank Reconciliation; a non-cheque payment is voided instead.',
    ],
    related: ['fin-bills', 'fin-bank-reconciliation', 'fin-uncleared-cheques'],
  },

  {
    id: 'fin-bank-deposit',
    section: 'finance',
    title: 'Bank Deposits',
    keywords: ['bank deposit', 'BD-', 'deposit slip', 'bank the cash', 'cheques in hand', 'deposit cheques', 'post-dated', 'stale cheque', 'slip no', 'deposit'],
    summary: 'Take cash and received cheques to the bank. The money moves from the cash book to the bank account.',
    where: 'Finance › Banking › Bank Deposits',
    route: '/finance/bank-deposits',
    who: 'View / Create / Edit / Confirm / Void Bank Deposits',
    flow: {
      title: 'Where the money moves',
      steps: [
        { label: 'Customer Receipt', sub: 'Cash → Cash Book; cheque → Cheques in Hand', tone: 'teal' },
        { label: 'Bank Deposit', sub: 'Tick cheques, enter cash', tone: 'violet' },
        { label: 'Bank account', sub: 'Waits for the statement', tone: 'blue' },
        { label: 'Bank Reconciliation', sub: 'Realize or Return', tone: 'green' },
      ],
    },
    steps: [
      'Click **+ Create Deposit**. Choose the **Company**, **Deposit Date** and **Deposit Into (Bank Account)**, and type the bank’s **Slip No**.',
      'For cash, choose the cash book it comes from and the amount. The **Available** cash is shown.',
      'Tick the cheques going on this slip (or tick all that can be deposited). Each shows its receipt, customer, cheque no, date and drawee bank.',
      'Check the **Total Deposit**, then click **Create & Close** and answer **Deposit now?**. Download the slip as PDF if needed.',
    ],
    statuses: { main: ['Draft', 'Confirmed'], side: [{ from: 'Confirmed', to: 'Voided', note: 'its cheques go back in hand' }] },
    rules: [
      'A post-dated cheque cannot be deposited before its date: “… is post-dated to … and cannot be deposited before then.”',
      'A cheque older than 6 months is **stale** and cannot be deposited.',
      'A cheque can be on only one deposit. A draft deposit already holds its cheques.',
      'You cannot deposit more cash than the cash book holds.',
      'A receipt whose cheque is on a deposit cannot be voided until the cheque is removed from that deposit.',
    ],
    related: ['flow-cheques', 'sal-receipt', 'fin-bank-reconciliation'],
  },

  {
    id: 'fin-bank-reconciliation',
    section: 'finance',
    title: 'Bank Reconciliation',
    keywords: ['bank reconciliation', 'BR-', 'reconcile', 'bank statement', 'realize', 'realized', 'returned cheque', 'bounce', 'not presented', 'variance', 'opening balance', 'bank charges', 'interest income', 'undo reconciliation'],
    summary: 'Match the bank account in the books to the bank statement, and record cheques that bounced.',
    where: 'Finance › Banking › Bank Reconciliation',
    route: '/finance/bank-reconciliations',
    who: 'View / Create / Edit / Post / Undo Bank Reconciliations',
    flow: {
      title: 'One statement',
      steps: [
        { label: 'Choose account & statement date', tone: 'slate' },
        { label: 'Enter statement balance', tone: 'blue' },
        { label: 'Mark each line', sub: 'Realize / Return / Not Presented', tone: 'violet' },
        { label: 'Add missing entries', sub: 'Bank charges, interest', tone: 'amber' },
        { label: 'Variance 0.00 → Post', tone: 'green' },
      ],
    },
    steps: [
      'Click **+ New Reconciliation**. Choose the **Bank Account** and the **Statement Date (To)**, then **Load**.',
      'Type the **Statement Balance** from the bank statement. On an account’s **first** reconciliation, also enter the **Start Date** and **Opening Balance** (entries before the start date count as already cleared).',
      'Lines appear in two lists: **Deposits** and **Withdrawals, Bank Charges and Issued Cheques**. For each line on the statement choose **Realize**. For a cheque the bank returned, choose **Return** and the date it was returned. Leave the others as **Not Presented**; they carry forward to next month.',
      'Missing an entry? Use **Add Transaction** to find it in the books, or **New Bank Entry** to post **Bank Charges** or **Interest Income**.',
      'When the variance is **0.00**, click **Post this reconciliation**. Attach the statement and **Save Draft** at any time before that.',
    ],
    example: {
      scenario: 'November statement for Commercial Bank – Current A/C.',
      table: {
        head: ['Line', 'Amount (Rs)', 'Mark'],
        rows: [
          ['Opening balance (last reconciliation)', '1,250,000', ''],
          ['BD-2026-0077 deposit', '+ 340,000', 'Realize'],
          ['Cheque 100233 to Lanka Textiles', '− 340,000', 'Not Presented (not on the statement yet)'],
          ['Bank charges (posted with New Bank Entry)', '− 1,500', 'Realize'],
          ['Closing = 1,250,000 + 340,000 − 1,500', '1,588,500', ''],
          ['Statement balance', '1,588,500', 'Variance 0.00 → Post'],
        ],
      },
      result: 'Cheque 100233 carries forward to December’s reconciliation. Posting closes November for this bank account.',
    },
    statuses: {
      main: ['Draft', 'Posted'],
      side: [{ from: 'Posted', to: 'Undone', note: 'only the latest one' }],
    },
    howItWorks: [
      'Closing = opening + realized deposits − realized withdrawals. Variance = statement balance − closing, and must be 0.00 to post.',
      'A **returned** customer cheque posts a bounce entry: the invoices it paid become unpaid again and the customer is flagged. A returned issued cheque reopens the GRNs or bills it paid.',
      'A cheque realized on an earlier statement and bounced on this one is entered as **Returned after clearing**.',
      'After posting, nothing can be dated on or before the statement date on this bank account.',
    ],
    rules: [
      '“The reconciliation does not balance: the difference is …”: find the missing or wrong line before posting.',
      'Only the **latest** reconciliation of an account can be undone.',
      'Statements must be reconciled in date order, with one draft per account at a time.',
    ],
    related: ['flow-cheques', 'fin-uncleared-cheques', 'fin-bank-deposit'],
  },

  {
    id: 'fin-uncleared-cheques',
    section: 'finance',
    title: 'Uncleared Cheques report',
    keywords: ['uncleared cheques', 'unpresented cheques', 'deposited not cleared', 'issued not presented', 'stale', 'outstanding cheques'],
    summary: 'Cheques still waiting on the bank on a given day: ones you issued that were not presented, and ones you deposited that have not cleared.',
    where: 'Finance › Banking › Uncleared Cheques',
    route: '/finance/reports/uncleared-cheques',
    who: 'View Bank Reconciliations or View Financial Reports',
    steps: [
      'Choose the **As at** date (required, not in the future), and optionally the company, bank account and type: **Issued, not presented** or **Deposited, not cleared**.',
      'Print, or download it as PDF or Excel (CSV).',
    ],
    howItWorks: [
      'A cheque leaves this list once a posted bank reconciliation marks it realized or returned.',
      'Flags: **Stale** (older than 6 months) and post-dated (cheque date after the As at date).',
    ],
    related: ['fin-bank-reconciliation', 'flow-cheques'],
  },

  {
    id: 'fin-postings-reference',
    section: 'finance',
    title: 'Which journal does each document post?',
    keywords: ['journal', 'posting', 'double entry', 'debit', 'credit', 'accounting entries', 'which account', 'GRN journal', 'invoice journal', 'accounts payable', 'accounts receivable'],
    summary: 'A reference of the accounting entry each confirmed document makes.',
    intro: ['Accounts are shown by **posting role**; the actual ledger account comes from **Account Mapping**. GRNs post to **Purchases** (periodic stock method), not to an Inventory account.'],
    tables: [{
      head: ['Document (when)', 'Debit', 'Credit'],
      rows: [
        ['GRN (confirm)', 'Purchases; Input Tax Recoverable (claimable VAT)', 'Accounts Payable (supplier)'],
        ['Supplier Return (confirm)', 'Accounts Payable (what the GRN still owed); Supplier Advances (the rest)', 'Purchase Returns; Input Tax Recoverable'],
        ['Supplier Payment (confirm)', 'Accounts Payable per GRN', 'Pay From cash/bank; Supplier Advances (set-offs); Discount Received'],
        ['Invoice (issue)', 'Accounts Receivable (customer)', 'Sales Revenue; Output Tax Payable (VAT); SSCL Payable'],
        ['Invoice (cancel)', 'A reversing journal of the above', ''],
        ['Customer Receipt (confirm)', 'Deposit To cash/bank/Cheques in Hand; Discount Allowed; Customer Advances (set-offs)', 'Accounts Receivable per invoice'],
        ['Customer Return (confirm)', 'Sales Returns; Output Tax Payable; SSCL Payable', 'Accounts Receivable (what the invoice still owed); Customer Advances (the rest)'],
        ['Bill (confirm)', 'Each line’s expense account; Input Tax Recoverable', 'Accounts Payable (supplier)'],
        ['Bill Payment (confirm)', 'Accounts Payable per bill', 'Cash book / bank paid from'],
        ['Bank Deposit (confirm)', 'Bank account', 'Cash Book (cash); Cheques in Hand (cheques)'],
        ['Returned cheque (reconciliation)', 'Receivables reopened (received cheque) / Bank (issued cheque)', 'Bank (received cheque) / Payables reopened (issued cheque)'],
        ['Any void', 'A reversing journal, dated on the reversal date', ''],
      ],
    }],
    rules: ['Delivery orders and costings do not post journals.'],
    related: ['fin-overview', 'fin-account-mapping'],
  },
]
