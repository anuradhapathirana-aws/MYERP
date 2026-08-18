/**
 * World-standard conversion factors for common unit dimensions.
 * Each group shares a common internal base (e.g. grams for mass, mm for length).
 * Factor = how many "internal base units" this unit equals.
 *
 * Rate formula: to find "1 baseUnit = X otherUnit"
 *   rate = baseFactor / otherFactor
 */
const GROUPS = [
  {
    name: 'mass',
    // internal base: gram (g = 1)
    units: {
      'µg': 1e-6, 'mcg': 1e-6, 'ug': 1e-6,
      'mg': 0.001,
      'g': 1,
      'kg': 1000,
      't': 1_000_000, 'mt': 1_000_000, 'tonne': 1_000_000,
      'oz': 28.349523125,
      'lb': 453.59237, 'lbs': 453.59237,
      'st': 6350.29318,
      'cwt': 45359.237,
      'ton': 907184.74,
    },
  },
  {
    name: 'length',
    // internal base: millimetre (mm = 1)
    units: {
      'mm': 1,
      'cm': 10,
      'dm': 100,
      'm': 1000,
      'km': 1_000_000,
      'in': 25.4,
      'ft': 304.8,
      'yd': 914.4,
      'mi': 1_609_344,
      'nmi': 1_852_000,
    },
  },
  {
    name: 'volume',
    // internal base: millilitre (ml = 1)
    units: {
      'µl': 0.001, 'ul': 0.001,
      'ml': 1,
      'cl': 10,
      'dl': 100,
      'l': 1000,
      'm³': 1_000_000, 'm3': 1_000_000,
      'fl oz': 29.5735, 'floz': 29.5735,
      'pt': 473.176,
      'qt': 946.353,
      'gal': 3785.41,
      'imp gal': 4546.09, 'impgal': 4546.09,
      'bbl': 158_987.3,
    },
  },
  {
    name: 'area',
    // internal base: mm²
    units: {
      'mm²': 1, 'mm2': 1,
      'cm²': 100, 'cm2': 100,
      'm²': 1_000_000, 'm2': 1_000_000,
      'km²': 1e12, 'km2': 1e12,
      'ha': 10_000_000_000,
      'in²': 645.16, 'in2': 645.16,
      'ft²': 92_903.04, 'ft2': 92_903.04,
      'yd²': 836_127.36, 'yd2': 836_127.36,
      'mi²': 2_589_988_110_336, 'mi2': 2_589_988_110_336,
      'acre': 4_046_856_422.4,
    },
  },
  {
    name: 'time',
    // internal base: second (s = 1)
    units: {
      'ms': 0.001,
      's': 1, 'sec': 1,
      'min': 60,
      'h': 3600, 'hr': 3600, 'hour': 3600,
      'd': 86400, 'day': 86400,
      'wk': 604800, 'week': 604800,
    },
  },
  {
    name: 'digital',
    // internal base: byte (B = 1)
    units: {
      'b': 1, 'byte': 1,
      'kb': 1024,
      'mb': 1_048_576,
      'gb': 1_073_741_824,
      'tb': 1_099_511_627_776,
      'pb': 1_125_899_906_842_624,
    },
  },
  {
    name: 'speed',
    // internal base: m/s
    units: {
      'm/s': 1, 'mps': 1,
      'km/h': 0.277778, 'kph': 0.277778,
      'mph': 0.44704,
      'ft/s': 0.3048, 'fps': 0.3048,
      'knot': 0.514444, 'kn': 0.514444,
    },
  },
  {
    name: 'pressure',
    // internal base: Pascal (Pa = 1)
    units: {
      'pa': 1,
      'kpa': 1000,
      'mpa': 1_000_000,
      'bar': 100_000,
      'mbar': 100,
      'psi': 6894.76,
      'atm': 101_325,
      'torr': 133.322,
      'mmhg': 133.322,
    },
  },
  {
    name: 'energy',
    // internal base: Joule (J = 1)
    units: {
      'j': 1,
      'kj': 1000,
      'mj': 1_000_000,
      'cal': 4.184,
      'kcal': 4184,
      'wh': 3600,
      'kwh': 3_600_000,
      'mwh': 3_600_000_000,
      'btu': 1055.06,
    },
  },
]

function findInGroup(symbol) {
  const key = symbol.toLowerCase().trim()
  for (const group of GROUPS) {
    for (const [sym, factor] of Object.entries(group.units)) {
      if (sym.toLowerCase() === key) return { group, factor }
    }
  }
  return null
}

function formatRate(value) {
  if (value === 0) return '0'
  // 8 significant figures, strip trailing zeros
  return String(parseFloat(value.toPrecision(8)))
}

/**
 * Given a base unit object and an array of other unit objects,
 * returns { [unitId]: rateString } for every unit whose symbol
 * matches a known world standard in the same dimension group.
 *
 * Rate meaning: "1 baseUnit = X otherUnit"
 */
export function computeStandardRates(baseUnit, otherUnits) {
  const baseResult = findInGroup(baseUnit.symbol)
  if (!baseResult) return {}

  const { group, factor: baseFactor } = baseResult
  const computed = {}

  for (const unit of otherUnits) {
    const key = unit.symbol.toLowerCase().trim()
    const entry = Object.entries(group.units).find(([sym]) => sym.toLowerCase() === key)
    if (entry) {
      const otherFactor = entry[1]
      computed[unit.id] = formatRate(baseFactor / otherFactor)
    }
  }

  return computed
}

/** Returns true if the symbol is recognised in any standard group. */
export function isKnownUnit(symbol) {
  return findInGroup(symbol) !== null
}
