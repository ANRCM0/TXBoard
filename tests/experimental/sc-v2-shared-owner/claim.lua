-- SC_OWNER_CLAIM_V1: private verified admission only; never release a tombstone.
if #KEYS ~= 2 or #ARGV ~= 5 then return -4 end
local function integer(value)
  if #value > 16 or not string.match(value, '^%d+$') then return nil end
  if #value > 1 and string.sub(value, 1, 1) == '0' then return nil end
  local number = tonumber(value)
  if not number or number < 1 or number > 9007199254740991 or number ~= math.floor(number) then return nil end
  return number
end
local issued, expires, local_now = integer(ARGV[3]), integer(ARGV[4]), integer(ARGV[5])
if not issued or not expires or not local_now or expires <= issued or expires - issued > 60000
  or #ARGV[1] ~= 77 or #ARGV[2] ~= 36 then return -4 end
if redis.call('GET', KEYS[1]) ~= ARGV[1] then return -1 end
local time = redis.call('TIME')
local now = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)
if math.abs(now - local_now) > 1000 then return -2 end
if now < issued - 5000 or now >= expires then return -3 end
local result = redis.call('SET', KEYS[2], ARGV[2], 'NX', 'PX', 70000)
if result and result.ok == 'OK' then return 1 end
return 0
