import Model from 'flarum/common/Model';
import User from 'flarum/common/models/User';

export default class UserMoneyHistory extends Model {
  balanceDelta = Model.attribute<number>('balance_delta');
  source = Model.attribute<string>('source');
  sourceKey = Model.attribute<string | null>('source_key');
  sourceParams = Model.attribute<Record<string, unknown> | null>('source_params');
  createdAt = Model.attribute<string>('created_at');
  balanceBefore = Model.attribute<number>('balance_before');
  balanceAfter = Model.attribute<number>('balance_after');
  user = Model.hasOne<User>('user');
  actor = Model.hasOne<User>('actor');
}
